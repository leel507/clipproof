<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine Abgabe: alle Entwürfe eines Gastes, mit einem Klick abgesendet. Eine Benachrichtigung je Abgabe.
 *
 * Vorgabewerte sind null: Nextcloud schreibt nur geänderte Felder (E3).
 *
 * @method int|null getReviewId()
 * @method void setReviewId(?int $reviewId)
 * @method int|null getVersionId()
 * @method void setVersionId(?int $versionId)
 * @method int|null getGuestId()
 * @method void setGuestId(?int $guestId)
 * @method int|null getCommentCount()
 * @method void setCommentCount(?int $commentCount)
 * @method string|null getProtocolText()
 * @method void setProtocolText(?string $protocolText)
 * @method int|null getCreatedAt()
 * @method void setCreatedAt(?int $createdAt)
 */
class Submission extends Entity {
	protected ?int $reviewId = null;
	protected ?int $versionId = null;
	protected ?int $guestId = null;
	protected ?int $commentCount = null;
	protected ?string $protocolText = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('reviewId', 'integer');
		$this->addType('versionId', 'integer');
		$this->addType('guestId', 'integer');
		$this->addType('commentCount', 'integer');
		$this->addType('protocolText', 'string');
		$this->addType('createdAt', 'integer');
	}
}
