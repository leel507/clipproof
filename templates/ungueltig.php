<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later
// Ungültiger, gesperrter oder abgelaufener Link. Absichtlich ohne Grund,
// damit niemand ausprobieren kann, welche Links es gibt.
/** @var \OCP\IL10N $l */
?>
<div class="guest-box">
	<h2><?php p($l->t('This link is not valid')); ?></h2>
	<p><?php p($l->t('The review link has expired, has been disabled or was copied incorrectly. Please ask the person who sent it to you.')); ?></p>
</div>
