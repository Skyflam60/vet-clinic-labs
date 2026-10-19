/*
 * mock-api.js — «движок» учебного сервера. ЭТОТ ФАЙЛ МЕНЯТЬ НЕ НУЖНО.
 *
 * Он перехватывает запросы fetch к адресам, начинающимся с /api/, находит ответ в таблице ROUTES
 * (файл mock-data.js), ждёт долю секунды, как настоящий сервер, и возвращает данные в формате JSON.
 * Если для запроса в ROUTES ничего не описано, сервер отвечает ошибкой 404.
 *
 * Команды для проверки страницы «в плохих условиях» (вводятся в консоли браузера, F12):
 *   MockApi.setDelay(2000, 3000)   // медленный сервер: ответ придёт через 2–3 секунды
 *   MockApi.setErrorRate(1)        // все запросы будут отвечать ошибкой 500
 *   MockApi.setNetworkDown(true)   // «пропал интернет»: fetch завершится ошибкой сети
 *   // вернуть нормальный режим: setDelay(300, 800), setErrorRate(0), setNetworkDown(false)
 */
(function () {
  'use strict';

  const config = { minDelay: 300, maxDelay: 800, errorRate: 0, networkDown: false };
  const realFetch = window.fetch.bind(window);

  function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  // Ответ сервера: код состояния + данные в формате JSON
  function makeResponse(status, data) {
    const hasBody = data !== undefined && data !== null && status !== 204;
    return new Response(hasBody ? JSON.stringify(data) : null, {
      status: status,
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    });
  }

  // reply(404, { error: '...' }) — для случаев, когда нужен код ответа, отличный от 200
  window.reply = function (status, data) {
    return { isReply: true, status: status, data: data };
  };

  // filterList(список, параметры) — выбирает из списка записи по параметрам запроса:
  //   ?status=done   — оставить записи, у которых поле status равно done (подойдёт любое поле);
  //   ?q=форма       — оставить записи, у которых в любом текстовом поле есть слово «форма»;
  //   пустое значение (?status=) означает «без фильтра».
  window.filterList = function (list, query) {
    return list.filter(function (item) {
      return Object.keys(query).every(function (key) {
        const value = query[key];
        if (value === '') {
          return true;
        }
        if (key === 'q') {
          const needle = value.toLowerCase();
          return Object.keys(item).some(function (field) {
            return typeof item[field] === 'string' && item[field].toLowerCase().indexOf(needle) !== -1;
          });
        }
        return String(item[key]) === value;
      });
    });
  };

  // Ищет в ROUTES строку для запроса. Адрес вида /api/tasks/:id подходит к /api/tasks/5
  function findRoute(method, pathname) {
    const keys = Object.keys(ROUTES);
    for (let i = 0; i < keys.length; i++) {
      const parts = keys[i].split(' ');                       // ['GET', '/api/tasks/:id']
      if (parts[0] !== method) {
        continue;
      }
      const names = [];
      const pattern = parts[1].replace(/:([A-Za-z]+)/g, function (all, name) {
        names.push(name);
        return '([^/]+)';
      });
      const match = pathname.match(new RegExp('^' + pattern + '$'));
      if (match) {
        const params = {};
        names.forEach(function (name, index) { params[name] = match[index + 1]; });
        return { handler: ROUTES[keys[i]], params: params };
      }
    }
    return null;
  }

  async function handle(url, init) {
    init = init || {};
    const method = (init.method || 'GET').toUpperCase();
    const parsed = new URL(url, 'http://localhost');

    let body = null;
    if (init.body) {
      try {
        body = JSON.parse(init.body);
      } catch (e) {
        return makeResponse(400, { error: 'Тело запроса не является корректным JSON' });
      }
    }

    await sleep(config.minDelay + Math.random() * (config.maxDelay - config.minDelay));
    if (config.networkDown) {
      throw new TypeError('Failed to fetch');
    }
    if (Math.random() < config.errorRate) {
      return makeResponse(500, { error: 'Внутренняя ошибка сервера' });
    }

    const route = findRoute(method, parsed.pathname);
    if (!route) {
      return makeResponse(404, {
        error: 'Для запроса «' + method + ' ' + parsed.pathname + '» в mock-data.js нет строки в таблице ROUTES'
      });
    }

    const query = Object.fromEntries(parsed.searchParams.entries());
    const result = route.handler(query, body, route.params);

    if (result && result.isReply) {
      return makeResponse(result.status, result.data);
    }
    return makeResponse(result === undefined ? 204 : 200, result);
  }

  window.fetch = function (input, init) {
    const url = typeof input === 'string' ? input : input.url;
    if (url.indexOf('/api/') === 0) {
      return handle(url, init);
    }
    return realFetch(input, init);
  };

  window.MockApi = {
    config: config,
    setErrorRate: function (rate) { config.errorRate = rate; },
    setNetworkDown: function (down) { config.networkDown = down; },
    setDelay: function (min, max) { config.minDelay = min; config.maxDelay = max; }
  };
})();
