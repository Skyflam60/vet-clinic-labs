// Учебные данные хранятся только до перезагрузки страницы.
const appointments = [
  {id: 1, animal_id: 1, title: 'Плановый осмотр', doctor: 'Иванова', date: '2026-11-01T10:00', status: 'scheduled'},
  {id: 2, animal_id: 2, title: 'Вакцинация кошки', doctor: 'Петров', date: '2026-11-02T10:00', status: 'done'},
  {id: 3, animal_id: 3, title: 'Повторный осмотр', doctor: 'Иванова', date: '2026-11-03T10:00', status: 'cancelled'},
  {id: 4, animal_id: 1, title: 'Осмотр уха', doctor: 'Петров', date: '2026-11-04T10:00', status: 'scheduled'},
  {id: 5, animal_id: 2, title: 'Обработка раны', doctor: 'Иванова', date: '2026-11-05T10:00', status: 'done'},
  {id: 6, animal_id: 3, title: 'Проверка зубов', doctor: 'Петров', date: '2026-11-06T10:00', status: 'cancelled'}
];
const animals = [
  {id: 1, name: 'Рекс', owner: 'Иванов И.И.'},
  {id: 2, name: 'Мурка', owner: 'Петрова А.А.'},
  {id: 3, name: 'Бобик', owner: 'Сидоров П.П.'}
];
const ROUTES = {
  'GET /api/appointments': function (query) { return filterList(appointments, query); },
  'GET /api/animals': function () { return animals; }
};
