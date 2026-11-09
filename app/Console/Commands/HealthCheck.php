<?php

namespace App\Console\Commands;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Checks every service AURA depends on and emails config('monitoring.alert_emails')
 * when one goes down, every remind_minutes while it stays down, and when it
 * recovers. Also reports a host reboot (uptime went backwards), which is how a
 * power cut shows up once the machine is back.
 *
 * State lives in the file cache, not the database one: it must survive MySQL being down.
 */
class HealthCheck extends Command
{
    protected $signature = 'app:health-check {--test : Send a test email and exit}';

    protected $description = 'Check AURA services and email an alert when one is down or recovers';

    public function handle(): int
    {
        if ($this->option('test')) {
            return $this->sendTest();
        }

        $state = Cache::store('file');
        $this->checkReboot($state);

        $rows = [];

        foreach ($this->checks() as $name => $probe) {
            [$ok, $detail] = $this->probe($probe);
            $rows[] = [$name, $ok ? 'ok' : 'DOWN', $detail];
            $this->track($state, $name, $ok, $detail);
        }

        $this->table(['service', 'status', 'detail'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array<string, Closure(): string>
     */
    private function checks(): array
    {
        return [
            'Backend (Laravel)' => fn () => $this->httpOk(config('monitoring.backend_url')),
            'AURA-AGENT' => function () {
                $base = rtrim((string) config('services.aura_agent.base_url'), '/');
                $health = Http::timeout(5)->get($base.'/v1/health')->throw()->json();
                throw_unless(($health['status'] ?? null) === 'ok', new \RuntimeException(json_encode($health)));

                return 'ollama '.($health['ollama'] ?? '?').' · activity_api '.($health['activity_api'] ?? '?');
            },
            'Ollama' => fn () => $this->httpOk(rtrim((string) config('services.ollama.base_url'), '/').'/api/tags'),
            'MySQL' => function () {
                DB::select('select 1');

                return 'connected';
            },
            'Open WebUI' => fn () => $this->httpOk(config('monitoring.openwebui_url')),
            'Disk' => function () {
                $path = storage_path();
                $free = disk_free_space($path) / disk_total_space($path) * 100;
                throw_if($free < config('monitoring.min_free_disk_percent'), new \RuntimeException(sprintf('free %.1f%%', $free)));

                return sprintf('free %.1f%%', $free);
            },
        ];
    }

    private function httpOk(string $url): string
    {
        $response = Http::timeout(5)->get($url);
        throw_unless($response->successful(), new \RuntimeException("HTTP {$response->status()}"));

        return "HTTP {$response->status()}";
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function probe(Closure $probe): array
    {
        try {
            return [true, $probe()];
        } catch (Throwable $e) {
            return [false, mb_strimwidth($e->getMessage(), 0, 200, '…')];
        }
    }

    private function track(Repository $state, string $name, bool $ok, string $detail): void
    {
        $key = 'health:'.md5($name);
        $s = $state->get($key, ['fails' => 0, 'down_since' => null, 'alerted_at' => null]);

        if ($ok) {
            if ($s['alerted_at'] !== null) {
                $this->notify("✅ กลับมาใช้งานได้: {$name}", "{$name} กลับมาทำงานปกติแล้ว\nล่มตั้งแต่ {$s['down_since']} รวม ".$this->since($s['down_since'])."\nสถานะ: {$detail}");
            }
            $state->forever($key, ['fails' => 0, 'down_since' => null, 'alerted_at' => null]);

            return;
        }

        $s['fails']++;
        $s['down_since'] ??= now()->toDateTimeString();
        $remindDue = $s['alerted_at'] !== null && Carbon::parse($s['alerted_at'])->diffInMinutes(now()) >= config('monitoring.remind_minutes');

        if (($s['alerted_at'] === null && $s['fails'] >= config('monitoring.fail_threshold')) || $remindDue) {
            $prefix = $s['alerted_at'] === null ? '🔴 ระบบล่ม' : '🔴 ยังล่มอยู่';
            $this->notify("{$prefix}: {$name}", "{$name} ไม่ตอบสนอง\nเริ่มตั้งแต่ {$s['down_since']} (".$this->since($s['down_since']).")\nรายละเอียด: {$detail}");
            $s['alerted_at'] = now()->toDateTimeString();
        }

        $state->forever($key, $s);
    }

    /**
     * /proc/uptime is the host's, even inside the container.
     */
    private function checkReboot(Repository $state): void
    {
        $uptime = (float) strtok((string) @file_get_contents('/proc/uptime'), ' ');
        $last = $state->get('health:uptime');

        if ($last !== null && $uptime > 0 && $uptime < $last) {
            $bootedAt = now()->subSeconds((int) $uptime)->toDateTimeString();
            $this->notify('⚠️ เซิร์ฟเวอร์เพิ่งบูตใหม่', "เครื่อง ".gethostname()." บูตใหม่เมื่อ {$bootedAt}\nถ้าไม่ได้สั่ง reboot เอง อาจเกิดจากไฟดับหรือเครื่องค้าง\nตรวจสาเหตุ: journalctl -b -1 -n 50");
        }

        $state->forever('health:uptime', $uptime);
    }

    private function notify(string $subject, string $body): void
    {
        $body .= "\n\nเวลา: ".now()->toDateTimeString()."\nเครื่อง: ".gethostname()."\nตรวจโดย: php artisan app:health-check";
        Log::warning('Health alert', ['subject' => $subject]);
        $this->warn($subject);

        $to = config('monitoring.alert_emails');

        if ($to === []) {
            return;
        }

        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject('[AURA] '.$subject));
        } catch (Throwable $e) {
            Log::error('Health alert email failed', ['subject' => $subject, 'error' => $e->getMessage()]);
        }
    }

    private function sendTest(): int
    {
        $to = config('monitoring.alert_emails');

        if ($to === []) {
            $this->error('HEALTH_ALERT_EMAILS is empty.');

            return self::FAILURE;
        }

        Mail::raw("ทดสอบระบบแจ้งเตือนของ AURA\nถ้าได้รับอีเมลนี้ แปลว่าการแจ้งเตือนเมื่อระบบล่มพร้อมใช้งาน\n\nเครื่อง: ".gethostname(), fn ($m) => $m->to($to)->subject('[AURA] ทดสอบการแจ้งเตือน'));
        $this->info('Test email sent to '.implode(', ', $to).' (mailer: '.config('mail.default').')');

        return self::SUCCESS;
    }

    private function since(?string $from): string
    {
        return $from ? Carbon::parse($from)->locale('th')->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) : '-';
    }
}
