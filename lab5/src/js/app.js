const names = { scheduled: 'Запланирован', done: 'Завершён', cancelled: 'Отменён' };
const form = document.getElementById('visit-form');
const rows = document.getElementById('rows');
const info = document.getElementById('info');
const load = document.getElementById('load');
const statusField = document.getElementById('status');
const search = document.getElementById('search');
const animalField = document.getElementById('animal');
let animalList = [];
let nextId = 100;
let requestNumber = 0;

function counter() {
  document.getElementById('count').textContent = rows.children.length;
  document.getElementById('table').hidden = rows.children.length === 0;
}
function cell(row, text) {
  const td = document.createElement('td');
  td.textContent = text;
  row.appendChild(td);
  return td;
}
function createRow(item) {
  const row = document.createElement('tr');
  row.setAttribute('data-id', item.id);
  const animal = animalList.find(function (a) { return a.id === item.animal_id; });
  cell(row, animal ? animal.name : 'Неизвестное животное');
  cell(row, item.title);
  cell(row, item.doctor);
  cell(row, item.date.replace('T', ' '));
  const state = cell(row, '');
  const actions = cell(row, '');
  const done = document.createElement('button');
  done.type = 'button';
  function updateStatus() {
    state.textContent = names[item.status];
    row.classList.toggle('done', item.status === 'done');
    done.textContent = item.status === 'done' ? 'Вернуть' : 'Завершить';
    done.setAttribute('title', item.status === 'done' ? 'Вернуть запланированный статус' : 'Отметить завершённым');
    done.disabled = item.status === 'cancelled';
  }
  done.addEventListener('click', function () {
    item.status = item.status === 'done' ? 'scheduled' : 'done';
    updateStatus();
  });
  actions.appendChild(done);
  const remove = document.createElement('button');
  remove.type = 'button';
  remove.textContent = 'Удалить из списка';
  remove.addEventListener('click', function () {
    if (confirm('Удалить строку из учебного списка?')) {
      row.remove();
      counter();
      info.textContent = rows.children.length ? 'Строка удалена.' : 'Приёмы не найдены.';
    }
  });
  actions.appendChild(remove);
  updateStatus();
  return row;
}
async function loadAppointments() {
  const query = new URLSearchParams({ status: statusField.value, q: search.value.trim() });
  const response = await fetch('/api/appointments?' + query);
  if (!response.ok) throw new Error('Ошибка сервера: ' + response.status);
  return await response.json();
}
async function refreshList() {
  const number = ++requestNumber;
  info.textContent = 'Загрузка…';
  info.classList.remove('error');
  load.disabled = true;
  rows.replaceChildren();
  counter();
  try {
    const response = await fetch('/api/animals');
    if (!response.ok) throw new Error('Ошибка сервера: ' + response.status);
    animalList = await response.json();
    const list = await loadAppointments();
    if (number !== requestNumber) return;
    animalField.replaceChildren();
    animalList.forEach(function (a) {
      const option = document.createElement('option');
      option.value = a.id;
      option.textContent = a.name + ' — ' + a.owner;
      animalField.appendChild(option);
    });
    document.getElementById('add').disabled = animalList.length === 0;
    list.forEach(function (item) { rows.appendChild(createRow(item)); });
    counter();
    info.textContent = list.length ? 'Данные загружены.' : 'Приёмы не найдены. Измените поиск или статус.';
  } catch (error) {
    if (number !== requestNumber) return;
    info.textContent = error.message === 'Failed to fetch' ? 'Нет связи с учебным сервером.' : error.message;
    info.classList.add('error');
  } finally {
    if (number === requestNumber) load.disabled = false;
  }
}
form.addEventListener('submit', function (event) {
  event.preventDefault();
  const title = document.getElementById('title').value.trim();
  const error = document.getElementById('form-error');
  error.textContent = '';
  if (title.length < 3) {
    error.textContent = 'Причина обращения должна содержать не менее трёх символов.';
    return;
  }
  if (!form.checkValidity()) {
    error.textContent = 'Выберите животное, укажите врача, дату и время.';
    return;
  }
  const item = { id: nextId++, animal_id: Number(animalField.value), title: title,
    doctor: document.getElementById('doctor').value.trim(), date: document.getElementById('date').value, status: 'scheduled' };
  if (!item.doctor) { error.textContent = 'Укажите врача.'; return; }
  rows.insertBefore(createRow(item), rows.firstChild);
  counter();
  info.classList.remove('error');
  info.textContent = 'Приём добавлен в учебный список.';
  form.reset();
});
load.addEventListener('click', refreshList);
statusField.addEventListener('change', refreshList);
search.addEventListener('input', refreshList);
refreshList();
