<h2>Приёмы</h2><form method="get" action="/appointments"><label>Статус <select name="status"><option value="">Все</option>
<?php foreach (statusNames() as $key => $name): ?><option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
</select></label><button>Показать</button></form>
<?php if (!$items): ?><p>Приёмы не найдены.</p><?php else: ?>
<table><tr><th>Причина обращения</th><th>Врач</th><th>Дата и время</th><th>Статус</th></tr>
<?php foreach ($items as $item): ?><tr><td><a href="/appointments/<?= e($item['id']) ?>"><?= e($item['title']) ?></a></td><td><?= e($item['doctor']) ?></td><td><?= e(str_replace('T', ' ', $item['date'])) ?></td><td><?= e(statusNames()[$item['status']]) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
