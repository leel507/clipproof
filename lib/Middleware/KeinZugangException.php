<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Middleware;

/** Der Nutzer ist in keiner der erlaubten Gruppen. */
class KeinZugangException extends \Exception {
}
