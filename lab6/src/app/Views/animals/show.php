<h2><?= e($animal['name']) ?></h2><p>Владелец: <?= e($animal['owner']) ?></p><h3>Приёмы животного</h3>
<?php if (!$items): ?><p>Приёмов пока нет.</p><?php endif; ?><ul><?php foreach ($items as $item): ?><li><a href="/appointments/<?= e($item['id']) ?>"><?= e($item['title']) ?></a> — <?= e(str_replace('T', ' ', $item['date'])) ?></li><?php endforeach; ?></ul><a href="/animals">Назад к животным</a>
