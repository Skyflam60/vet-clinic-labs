<h2>Запись на приём</h2><form method="post" action="/appointments" novalidate>
<label>Животное <select name="animal_id"><option value="">Выберите животное</option><?php foreach ($animals as $animal): ?><option value="<?= e($animal['id']) ?>" <?= (string) ($values['animal_id'] ?? '') === (string) $animal['id'] ? 'selected' : '' ?>><?= e($animal['name']) ?></option><?php endforeach; ?></select></label>
<p class="error"><?= e($errors['animal_id'] ?? '') ?></p>
<?php foreach (['title' => 'Причина обращения', 'doctor' => 'Врач', 'date' => 'Дата и время'] as $key => $label): ?>
<label><?= e($label) ?> <input name="<?= e($key) ?>" type="<?= $key === 'date' ? 'datetime-local' : 'text' ?>" value="<?= e($values[$key] ?? '') ?>"></label><p class="error"><?= e($errors[$key] ?? '') ?></p><?php endforeach; ?>
<label>Статус <select name="status"><?php foreach (statusNames() as $key => $name): ?><option value="<?= e($key) ?>" <?= ($values['status'] ?? 'scheduled') === $key ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label><p class="error"><?= e($errors['status'] ?? '') ?></p>
<button>Сохранить</button><a href="/appointments">Отмена</a></form>
