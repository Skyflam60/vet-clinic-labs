// api.js — все обращения к серверу собраны в этом файле.
// Остальной код вызывает getAppointments(), createAppointment() и т.д. и не думает про заголовки, адреса и коды ответов.

// ---------- Токен и вошедший пользователь ----------
// После входа сервер выдаёт токен — «пропуск». Он хранится в браузере (localStorage) и
// отправляется с каждым запросом в заголовке Authorization. Вместе с ним сохраняем данные пользователя (имя, роль).

function getToken() {
  return localStorage.getItem('token');
}

// Вошедший пользователь: { id, name, email, role } или null
function currentUser() {
  try {
    return JSON.parse(localStorage.getItem('user'));
  } catch (error) {
    return null;
  }
}

// Заголовки, которые нужно отправлять с каждым запросом
function authHeaders() {
  return {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + getToken()
  };
}

// ---------- Проверка ответа сервера ----------
// Функция fetch не считает ошибками коды 401, 404, 422, 500: она «ругается» только когда нет связи.
// Поэтому response.ok проверяем сами и превращаем ошибку в исключение с понятными полями:
//   error.message — текст от сервера, error.status — код ответа (у ошибок сети его нет),
//   error.fields  — ошибки полей { title: '...', animal_id: '...' } (коды 422 и 409)
async function checkResponse(response, isLogin) {
  if (response.ok) {
    return;
  }

  let data = {};
  try {
    data = await response.json();
  } catch (error) {
    // у ответа может не быть тела (или оно не JSON): тогда останутся значения по умолчанию
  }

  const error = new Error(data.error || 'Ошибка сервера: ' + response.status);
  error.status = response.status;
  error.fields = data.errors || {};          // сервер присылает объект { поле: сообщение }

  if (response.status === 401 && !isLogin) {  // токен недействителен: срок вышел или токен отозван
    handleUnauthorized();                     // эта функция написана в app.js
  }
  throw error;
}

// Общий запрос к API с токеном: метод, адрес и (необязательно) тело
async function request(method, path, body) {
  const options = { method: method, headers: authHeaders() };
  if (body !== undefined) {
    options.body = JSON.stringify(body);      // объект -> строка JSON
  }
  const response = await fetch(API_BASE + path, options);
  await checkResponse(response);
  return response.status === 204 ? null : await response.json();   // у ответа 204 тела нет
}

// ---------- Вход и выход ----------

// POST /api/auth/login — отправляем email и пароль, получаем { token, expires_at, user }
async function login(email, password) {
  const response = await fetch(API_BASE + '/api/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email, password: password })
  });
  await checkResponse(response, true);      // true: для входа ответ 401 означает «неверный пароль», а не «сессия истекла»
  return await response.json();
}

// GET /api/auth/me — кто вошёл по этому токену (заодно проверяет, что токен ещё действует)
function me() {
  return request('GET', '/api/auth/me');
}

// POST /api/auth/logout — сообщаем серверу, что токен больше не нужен
async function logout() {
  try {
    await fetch(API_BASE + '/api/auth/logout', { method: 'POST', headers: authHeaders() });
  } catch (error) {
    // сервер недоступен: всё равно выходим, токен удалится из браузера
  }
}

// ---------- Справочники: животные, владельцы, врачи ----------

function getAnimals() {
  return request('GET', '/api/animals');
}

function getOwners() {
  return request('GET', '/api/owners');
}

function getDoctors() {
  return request('GET', '/api/doctors');
}

// POST /api/animals — { name, owner_id, species }
function createAnimal(animal) {
  return request('POST', '/api/animals', animal);
}

// DELETE /api/animals/1 — ответ 204 без тела
function deleteAnimal(id) {
  return request('DELETE', '/api/animals/' + encodeURIComponent(id));
}

// ---------- Приёмы ----------

// GET /api/appointments?sort=-id&status=scheduled&q=текст
function getAppointments(status, text) {
  let path = '/api/appointments?sort=-id';               // sort=-id: новые записи сверху
  if (status !== '') {
    path = path + '&status=' + encodeURIComponent(status);
  }
  if (text !== '') {
    path = path + '&q=' + encodeURIComponent(text);     // encodeURIComponent: знаки & # + и пробелы иначе ломают адрес
  }
  return request('GET', path);
}

// POST /api/appointments — { animal_id, doctor_id, title, date }; сервер вернёт приём с номером (id)
function createAppointment(appointment) {
  return request('POST', '/api/appointments', appointment);
}

// PATCH /api/appointments/5 — меняем только присланные поля, например { status: 'done' }
function updateAppointment(id, changes) {
  return request('PATCH', '/api/appointments/' + encodeURIComponent(id), changes);
}

// DELETE /api/appointments/5 — тела у ответа нет (код 204)
function deleteAppointment(id) {
  return request('DELETE', '/api/appointments/' + encodeURIComponent(id));
}
