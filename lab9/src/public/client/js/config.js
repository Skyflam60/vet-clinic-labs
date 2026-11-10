// config.js — адрес сервера API (ГОТОВО).
//
// Пустая строка: клиент и API находятся на одном адресе, запросы вида /api/appointments уходят туда же,
// откуда загружена страница (http://localhost:8000/client/ → http://localhost:8000/api/appointments).
//
// Если клиент запущен на другом адресе (например, php -S localhost:5500 -t public/client),
// укажите адрес сервера и добавьте адрес клиента в config/app.php → cors_origins:
//   const API_BASE = 'http://localhost:8000';
const API_BASE = '';
