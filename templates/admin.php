<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Admin-Einstellungen von Clipproof.
 *
 * @var array{gruppen: string, tage: int, jahre: int, protokoll: int, einbettung: string, ordnersprache: string, ziel: string} $_
 * @var \OCP\IL10N $l
 */
?>
<div id="clipproof" class="section">
	<h2><?php p($l->t('Clipproof')); ?></h2>
	<form method="post" action="<?php p($_['ziel']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<p>
			<label for="clipproof-gruppen"><?php p($l->t('Groups that may use Clipproof')); ?></label><br>
			<input id="clipproof-gruppen" type="text" name="gruppen" value="<?php p($_['gruppen']); ?>" placeholder="<?php p($l->t('All users')); ?>" style="width: 30em">
			<br><em><?php p($l->t('Comma separated group names. Empty means all users. Administrators always have access.')); ?></em>
		</p>
		<p>
			<label for="clipproof-tage"><?php p($l->t('Days the client link stays valid after approval')); ?></label><br>
			<input id="clipproof-tage" type="number" name="tage" min="1" max="3650" value="<?php p((string)$_['tage']); ?>">
			<br><em><?php p($l->t('After that the review is archived: read only, unsent drafts and guests without comments are deleted.')); ?></em>
		</p>
		<p>
			<label for="clipproof-jahre"><?php p($l->t('Years to keep sent comments in the archive')); ?></label><br>
			<input id="clipproof-jahre" type="number" name="jahre" min="0" max="30" value="<?php p((string)$_['jahre']); ?>">
			<br><em><?php p($l->t('0 keeps them until the owner deletes the review. Otherwise each submission is deleted that many years after the end of the year it was sent, for example to match statutory retention periods.')); ?></em>
		</p>
		<p>
			<label for="clipproof-protokoll"><?php p($l->t('Years to keep the deletion log')); ?></label><br>
			<input id="clipproof-protokoll" type="number" name="protokoll" min="1" max="30" value="<?php p((string)$_['protokoll']); ?>">
			<br><em><?php p($l->t('The log records only that and when something was deleted, never the content.')); ?></em>
		</p>
		<p>
			<label for="clipproof-einbettung"><?php p($l->t('Sites allowed to embed the client page')); ?></label><br>
			<input id="clipproof-einbettung" type="text" name="einbettung" value="<?php p($_['einbettung']); ?>" placeholder="https://example.org" style="width: 30em">
			<br><em><?php p($l->t('Space separated origins such as https://example.org. Empty means the client page cannot be embedded.')); ?></em>
		</p>
		<p>
			<label for="clipproof-ordnersprache"><?php p($l->t('Language of the subfolders in project folders')); ?></label><br>
			<select id="clipproof-ordnersprache" name="ordnersprache">
				<option value="en"<?php if ($_['ordnersprache'] === 'en') { print_unescaped(' selected'); } ?>>English: Previous versions, Assets</option>
				<option value="de"<?php if ($_['ordnersprache'] === 'de') { print_unescaped(' selected'); } ?>>Deutsch: Vorversionen, Assets</option>
			</select>
			<br><em><?php p($l->t('Older versions are moved into this folder. Existing folders in either language keep working.')); ?></em>
		</p>
		<p><button type="submit" class="primary"><?php p($l->t('Save')); ?></button></p>
	</form>
</div>
