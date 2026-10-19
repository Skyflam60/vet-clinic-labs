<h2><?= e($item['title']) ?></h2><p>Номер приёма: <?= e($item['id']) ?></p>
<p>Животное: <?php if ($animal): ?><a href="/animals/<?= e($animal['id']) ?>"><?= e($animal['name']) ?></a><?php else: ?>не найдено<?php endif; ?></p>
<p>Врач: <?= e($item['doctor']) ?></p><p>Дата и время: <?= e(str_replace('T', ' ', $item['date'])) ?></p><p>Статус: <?= e(statusNames()[$item['status']]) ?></p><a href="/appointments">Назад к списку</a>
