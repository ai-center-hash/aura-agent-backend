<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
# aura-agent-backend

## ทดสอบบนเครื่อง (ไม่ต้องมี agent จริง)

ต้องมี PHP 8.3+ (เปิด extension `pdo_sqlite`, `sqlite3`) และ Composer

```sh
composer install
cp .env.example .env
php artisan key:generate
```

แก้ `.env` ให้ใช้ SQLite และ mock agent:

```dotenv
DB_CONNECTION=sqlite            # ใส่ # หน้า DB_HOST … DB_PASSWORD
AURA_AGENT_URL=http://127.0.0.1:8199
AURA_AGENT_SERVICE_KEY=local-mock-key
FREYA_AGENT_URL=http://127.0.0.1:8199
FREYA_AGENT_SERVICE_KEY=local-mock-key
```

```sh
touch database/database.sqlite          # PowerShell: New-Item database/database.sqlite
php artisan migrate
php artisan app:create-admin admin@aura.local   # ถามรหัสผ่าน (อย่างน้อย 8 ตัว)

php -S 127.0.0.1:8199 dev/mock-agent.php        # terminal 1: mock AURA/FREYA
php artisan serve                               # terminal 2: http://localhost:8000
```

เปิด http://localhost:8000/login แล้ว login ด้วย admin ที่สร้าง ในหน้าแชทเลือก AURA-AGENT หรือ FREYA-AGENT ได้ก่อนเริ่มแชทใหม่

- admin ที่ login ด้วยรหัสผ่านไม่มี YRU Passport token: AURA จะตอบ `PASSPORT_REQUIRED` ส่วน FREYA ตอบแบบผู้ใช้ทั่วไป (กติกากลาง)
- mock: ข้อความที่มีคำว่า `expired` → `TOKEN_EXPIRED`, `down` → `LLM_UNAVAILABLE` (ดูหัวไฟล์ `dev/mock-agent.php`)
- การ์ด Ollama ในสถานะระบบจะขึ้น "ขัดข้อง" ถ้าไม่ได้รัน Ollama (ใช้แค่สร้างชื่อแชทใน Open WebUI)
- เทส: `php artisan test` · Postman: `docs/postman/` (environment ตั้ง `baseUrl` เป็น http://localhost:8000)
