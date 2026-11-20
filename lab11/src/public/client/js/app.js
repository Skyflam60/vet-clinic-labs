// app.js — логика страницы: вывод данных, обработка событий, запросы к серверу (через функции из api.js).

// ============================================================
// 1. Находим нужные элементы страницы (по их id из index.html)
// ============================================================
const loginScreen = document.getElementById('login-screen');
const mainScreen = document.getElementById('main-screen');
const loginForm = document.getElementById('login-form');
const visitForm = document.getElementById('visit-form');
const visitBody = document.getElementById('visit-body');
const visitTable = document.getElementById('visit-table');
const messageBox = document.getElementById('message');
const statusFilter = document.getElementById('status-filter');
const searchInput = document.getElementById('search-input');
const animalForm = document.getElementById('animal-form');
const animalBody = document.getElementById('animal-body');
const animalTable = document.getElementById('animal-table');
const animalMessage = document.getElementById('animal-message');

// Подписи (на сервере хранятся английские слова)
const STATUS_NAMES = { scheduled: 'Запланирован', done: 'Проведён', cancelled: 'Отменён' };
const ROLE_NAMES = { admin: 'Администратор', registrar: 'Регистратор', doctor: 'Врач' };
const SPECIES_NAMES = { dog: 'Собака', cat: 'Кошка', bird: 'Птица', rodent: 'Грызун', other: 'Другое' };

// Какой блок с ошибкой на странице соответствует полю, которое сервер назвал в ответе 422 или 409
const VISIT_ERROR_IDS = { animal_id: 'animal', title: 'title', doctor_id: 'doctor', date: 'date' };
const ANIMAL_ERROR_IDS = { name: 'animal-name', owner_id: 'animal-owner', species: 'animal-form' };

let requestCounter = 0;       // номер последнего запроса списка (чтобы старый ответ не затёр новый)
let searchTimer = null;       // таймер задержки поиска

// ============================================================
// 2. Вспомогательные функции
// ============================================================

// Показать один из двух экранов: 'login' или 'main'
function showScreen(name) {
  loginScreen.hidden = (name !== 'login');
  mainScreen.hidden = (name !== 'main');
}

// Защита от XSS: заменяет символы < > & " на безопасные коды,
// чтобы текст от пользователя нельзя было превратить в HTML-код
function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// Дата и время из формата API 2026-11-20T10:00 в 20.11.2026 10:00
function formatDateTime(value) {
  if (!value) {
    return '—';
  }
  const [date, time] = value.split('T');
  const parts = date.split('-');
  return parts[2] + '.' + parts[1] + '.' + parts[0] + ' ' + time;
}

// Права по роли — только для удобства интерфейса. Настоящая проверка — на сервере (require_role).
function hasRole(...roles) {
  const user = currentUser();
  return user !== null && roles.includes(user.role);
}

// Сообщение над таблицей: «Загрузка…», «Ничего не найдено», ошибка
function showMessage(box, text, isError) {
  box.textContent = text;
  box.className = isError ? 'message error-message' : 'message';
  box.hidden = false;
}

// Красный текст ошибки под полем: setError('title', 'Введите причину')
function setError(name, text) {
  document.getElementById('error-' + name).textContent = text;
}

// Убрать все красные сообщения об ошибках
function clearErrors() {
  const errors = document.querySelectorAll('.error');
  for (let i = 0; i < errors.length; i++) {
    errors[i].textContent = '';
  }
}

// Понятный пользователю текст ошибки запроса.
// У ошибок сети (сервер выключен, нет интернета) кода ответа нет: error.status === undefined.
function describeError(error, action) {
  if (error.status === undefined) {
    return 'Нет связи с сервером. Проверьте подключение и повторите.';
  }
  if (error.status === 403) {
    return 'Недостаточно прав для этого действия.';
  }
  if (error.status === 409) {
    return error.message;                           // конфликт с данными: сервер объясняет сам («у животного есть приёмы»)
  }
  if (error.status === 503) {
    return 'База данных недоступна. Попробуйте позже.';
  }
  if (error.status >= 500) {
    return action + ': ошибка сервера, попробуйте позже.';
  }
  return action + ': ' + error.message;
}

// Показывает под полями формы ошибки, которые прислал сервер: { title: '...', animal_id: '...' }.
// ids — соответствие «поле сервера → блок ошибки». Возвращает true, если хотя бы одну ошибку удалось показать у поля.
function showFieldErrors(fields, ids) {
  let shown = false;
  for (const name in fields) {
    if (ids[name]) {
      setError(ids[name], fields[name]);
      shown = true;
    }
  }
  return shown;
}

// Сервер сообщил, что токен недействителен (срок вышел или токен отозван): возвращаем пользователя ко входу.
// Эту функцию вызывает checkResponse из api.js.
function handleUnauthorized() {
  localStorage.removeItem('token');
  localStorage.removeItem('user');
  clearData();
  showScreen('login');
  setError('login', 'Сессия истекла. Войдите снова.');
}

// Очищает данные на странице, чтобы следующий вошедший не увидел чужое
function clearData() {
  visitBody.innerHTML = '';
  animalBody.innerHTML = '';
  messageBox.hidden = true;
  animalMessage.hidden = true;
}

// ============================================================
// 3. Вывод данных в таблицы
// ============================================================
function renderAppointments(items) {
  let html = '';
  for (let i = 0; i < items.length; i++) {
    const item = items[i];

    // Кнопки в последнем столбце: проведён и отмена — для запланированных, удаление — только администратору
    let actions = '';
    if (item.status === 'scheduled') {
      actions += `<button class="status-button" data-id="${item.id}" data-status="done">Проведён</button> `;
      actions += `<button class="status-button" data-id="${item.id}" data-status="cancelled">Отменить</button> `;
    }
    if (hasRole('admin')) {                          // кнопку «Удалить» видит только администратор
      actions += `<button class="danger delete-button" data-id="${item.id}">Удалить</button>`;
    }

    html += `
      <tr class="status-${escapeHtml(item.status)}">
        <td>${item.id}</td>
        <td>${formatDateTime(item.date)}</td>
        <td>${escapeHtml(item.animal || '—')}</td>
        <td>${escapeHtml(item.owner || '—')}</td>
        <td>${escapeHtml(item.title)}</td>
        <td>${escapeHtml(item.doctor || 'не назначен')}</td>
        <td>${STATUS_NAMES[item.status] || escapeHtml(item.status)}</td>
        <td class="actions">${actions}</td>
      </tr>`;
  }

  visitBody.innerHTML = html;
  visitTable.hidden = (items.length === 0);          // пустую таблицу не показываем

  // Кнопки появились только что, поэтому обработчики вешаем после вывода таблицы
  document.querySelectorAll('.status-button').forEach(function (button) {
    button.addEventListener('click', onStatusClick);
  });
  document.querySelectorAll('.delete-button').forEach(function (button) {
    button.addEventListener('click', onDeleteClick);
  });
}

function renderAnimals(animals) {
  let html = '';
  for (let i = 0; i < animals.length; i++) {
    const a = animals[i];
    const actions = hasRole('admin')
      ? `<button class="danger animal-delete" data-id="${a.id}" data-name="${escapeHtml(a.name)}">Удалить</button>`
      : '';
    html += `
      <tr>
        <td>${a.id}</td>
        <td>${escapeHtml(a.name)}</td>
        <td>${SPECIES_NAMES[a.species] || escapeHtml(a.species)}</td>
        <td>${escapeHtml(a.owner || '—')}</td>
        <td class="actions">${actions}</td>
      </tr>`;
  }
  animalBody.innerHTML = html;
  animalTable.hidden = (animals.length === 0);
  document.querySelectorAll('.animal-delete').forEach(function (button) {
    button.addEventListener('click', onAnimalDeleteClick);
  });
}

// Заполняет выпадающий список: items — массив, label — функция «запись → подпись»
function fillSelect(id, items, label, emptyText) {
  let html = emptyText ? `<option value="">${escapeHtml(emptyText)}</option>` : '';
  for (let i = 0; i < items.length; i++) {
    html += `<option value="${items[i].id}">${escapeHtml(label(items[i]))}</option>`;
  }
  document.getElementById(id).innerHTML = html;
}

// ============================================================
// 4. Загрузка данных с сервера (асинхронные запросы)
// ============================================================

// Справочники для форм и таблица животных: животные, владельцы, врачи
async function loadReferences() {
  const [animals, owners, doctors] = await Promise.all([getAnimals(), getOwners(), getDoctors()]);
  fillSelect('visit-animal', animals, a => a.name + ' — ' + (a.owner || 'владелец не указан'));
  fillSelect('visit-doctor', doctors.filter(d => d.is_active), d => d.name + ' (' + d.specialty + ')', 'Врач не назначен');
  fillSelect('animal-owner', owners, o => o.name + ', ' + o.phone);
  document.getElementById('visit-button').disabled = (animals.length === 0);
  renderAnimals(animals);
}

// Загружает приёмы с учётом фильтров и выводит их в таблицу
async function refreshList() {
  const myRequest = ++requestCounter;               // номер этого запроса
  showMessage(messageBox, 'Загрузка…', false);

  try {
    const items = await getAppointments(statusFilter.value, searchInput.value.trim());
    if (myRequest !== requestCounter) {
      return;                                       // пока ждали ответ, отправили более новый запрос: этот ответ устарел
    }
    renderAppointments(items);
    if (items.length === 0) {
      showMessage(messageBox, 'Приёмы не найдены. Измените поиск или статус.', false);
    } else {
      messageBox.hidden = true;
    }
  } catch (error) {
    if (myRequest !== requestCounter || error.status === 401) {
      return;                                       // устаревший ответ или уже открыт экран входа
    }
    renderAppointments([]);
    showMessage(messageBox, describeError(error, 'Не удалось загрузить приёмы'), true);
  }
}

// ============================================================
// 5. Обработка событий
// ============================================================

// --- Вход ---
async function onLoginSubmit(event) {
  event.preventDefault();                           // не перезагружать страницу при отправке формы
  clearErrors();

  const email = document.getElementById('login-email').value.trim();
  const password = document.getElementById('login-password').value;

  // Проверка на стороне клиента: не отправляем заведомо неверные данные
  let hasError = false;
  if (email.indexOf('@') === -1) {
    setError('email', 'Введите корректный email');
    hasError = true;
  }
  if (password.length < 6) {
    setError('password', 'Пароль — не короче 6 символов');
    hasError = true;
  }
  if (hasError) {
    return;
  }

  const button = document.getElementById('login-button');
  button.disabled = true;                           // защита от повторного нажатия
  try {
    const result = await login(email, password);
    localStorage.setItem('token', result.token);
    localStorage.setItem('user', JSON.stringify(result.user));
    openMainScreen();
  } catch (error) {
    if (error.status === 401) {
      setError('login', 'Неверный email или пароль');
    } else {
      setError('login', describeError(error, 'Не удалось войти'));
    }
  } finally {
    button.disabled = false;                        // выполняется в любом случае
  }
}

// --- Запись на приём ---
async function onVisitSubmit(event) {
  event.preventDefault();
  clearErrors();

  const title = document.getElementById('visit-title').value.trim();
  const date = document.getElementById('visit-date').value;       // 2026-11-20T10:00 — тот же формат, что у API
  const animalId = document.getElementById('visit-animal').value;
  const doctorId = document.getElementById('visit-doctor').value;

  let hasError = false;
  if (title.length < 3) {
    setError('title', 'Причина обращения — не короче 3 символов');
    hasError = true;
  }
  if (date === '') {
    setError('date', 'Укажите дату и время приёма');
    hasError = true;
  }
  if (hasError) {
    return;
  }

  const button = document.getElementById('visit-button');
  button.disabled = true;
  try {
    await createAppointment({
      animal_id: Number(animalId),                   // значение select — строка, а серверу нужно число
      doctor_id: doctorId === '' ? null : Number(doctorId),   // «врач не назначен» — null, а не пустая строка
      title: title,
      date: date
    });
    visitForm.reset();
    await refreshList();
  } catch (error) {
    // ошибки проверки (422) и занятое время врача (409) сервер привязывает к полям
    if ((error.status === 422 || error.status === 409) && showFieldErrors(error.fields, VISIT_ERROR_IDS)) {
      return;
    }
    if (error.status !== 401) {
      setError('form', describeError(error, 'Не удалось записать на приём'));
    }
  } finally {
    button.disabled = false;
  }
}

// --- Отметить приём проведённым или отменить ---
async function onStatusClick(event) {
  const button = event.target;
  button.disabled = true;
  try {
    await updateAppointment(button.dataset.id, { status: button.dataset.status });   // PATCH: меняем только статус
    await refreshList();
  } catch (error) {
    button.disabled = false;
    if (error.status !== 401) {
      showMessage(messageBox, describeError(error, 'Не удалось изменить приём'), true);
    }
  }
}

// --- Удаление приёма ---
async function onDeleteClick(event) {
  const id = event.target.dataset.id;
  if (!confirm('Удалить приём №' + id + '? Медицинская запись приёма тоже будет удалена.')) {
    return;                                         // пользователь нажал «Отмена»
  }
  event.target.disabled = true;
  try {
    await deleteAppointment(id);
    await refreshList();
  } catch (error) {
    event.target.disabled = false;
    if (error.status === 404) {
      await refreshList();                          // приём уже удалили (например, в другой вкладке)
      showMessage(messageBox, 'Приём №' + id + ' не найден: список обновлён.', true);
    } else if (error.status !== 401) {
      showMessage(messageBox, describeError(error, 'Не удалось удалить'), true);
    }
  }
}

// --- Новое животное ---
async function onAnimalSubmit(event) {
  event.preventDefault();
  clearErrors();
  const name = document.getElementById('animal-name').value.trim();
  if (name.length < 3) {
    setError('animal-name', 'Кличка — не короче 3 символов');
    return;
  }
  const button = document.getElementById('animal-button');
  button.disabled = true;
  try {
    const animal = await createAnimal({
      name: name,
      owner_id: Number(document.getElementById('animal-owner').value),
      species: document.getElementById('animal-species').value
    });
    animalForm.reset();
    await loadReferences();
    showMessage(animalMessage, 'Животное «' + animal.name + '» добавлено: его можно записать на приём.', false);
  } catch (error) {
    if (error.status === 422 && showFieldErrors(error.fields, ANIMAL_ERROR_IDS)) {
      return;
    }
    if (error.status !== 401) {
      setError('animal-form', describeError(error, 'Не удалось добавить животное'));
    }
  } finally {
    button.disabled = false;
  }
}

// --- Удаление животного (у животного с приёмами сервер ответит 409) ---
async function onAnimalDeleteClick(event) {
  const button = event.target;
  if (!confirm('Удалить животное «' + button.dataset.name + '»?')) {
    return;
  }
  button.disabled = true;
  try {
    await deleteAnimal(button.dataset.id);
    await loadReferences();
    showMessage(animalMessage, 'Животное удалено.', false);
  } catch (error) {
    button.disabled = false;
    if (error.status !== 401) {
      showMessage(animalMessage, describeError(error, 'Не удалось удалить'), true);
    }
  }
}

// --- Выход ---
async function onLogoutClick() {
  await logout();                                   // сообщаем серверу: токен больше не нужен
  localStorage.removeItem('token');
  localStorage.removeItem('user');
  clearData();                                      // чтобы следующий вошедший не увидел чужие данные
  clearErrors();
  document.getElementById('login-password').value = '';
  showScreen('login');
}

// Поиск: ждём 300 мс после последней нажатой клавиши, чтобы не отправлять запрос на каждую букву
function onSearchInput() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(refreshList, 300);
}

// Кнопка «Обновить»: и справочники, и список приёмов
async function onRefreshClick() {
  try {
    await loadReferences();
  } catch (error) {
    if (error.status !== 401) {
      showMessage(animalMessage, describeError(error, 'Не удалось загрузить животных'), true);
    }
  }
  await refreshList();
}

// Подключаем обработчики: «когда произойдёт ЭТО событие у ЭТОГО элемента — вызвать ЭТУ функцию»
loginForm.addEventListener('submit', onLoginSubmit);
visitForm.addEventListener('submit', onVisitSubmit);
animalForm.addEventListener('submit', onAnimalSubmit);
statusFilter.addEventListener('change', refreshList);
searchInput.addEventListener('input', onSearchInput);
document.getElementById('refresh-button').addEventListener('click', onRefreshClick);
document.getElementById('logout-button').addEventListener('click', onLogoutClick);

// ============================================================
// 6. Запуск страницы
// ============================================================
async function openMainScreen() {
  const user = currentUser();
  document.getElementById('user-name').textContent = user.name;
  document.getElementById('user-role').textContent = ROLE_NAMES[user.role] || user.role;
  animalForm.hidden = !hasRole('admin', 'registrar');   // врач животных не добавляет: форму не показываем
  showScreen('main');
  await onRefreshClick();
}

// Токен мог остаться от прошлого визита, но уже не действовать: спрашиваем сервер, кто мы
async function start() {
  if (!getToken()) {
    showScreen('login');
    return;
  }
  try {
    const user = await me();
    localStorage.setItem('user', JSON.stringify(user));
    openMainScreen();
  } catch (error) {
    showScreen('login');
    if (error.status !== 401) {
      setError('login', describeError(error, 'Не удалось проверить вход'));
    }
  }
}

start();
