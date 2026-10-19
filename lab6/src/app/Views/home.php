<h2>Главная страница</h2><div class="cards">
<?php foreach (statusNames() as $key => $name): ?><p><?= e($name) ?>: <?= e($counts[$key]) ?></p><?php endforeach; ?>
<p>Животных: <?= e($animalCount) ?></p></div>
