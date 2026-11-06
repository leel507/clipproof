<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Eine Anmerkung an einem Frame. Ohne submissionId ist sie bei Kunden ein Entwurf, den nur ihr Verfasser sieht.
 *
 * Vorgabewerte sind null: Nextcloud schreibt nur geänderte Felder (E3).
 *
 * @method int|null getVersionId()
 * @method void setVersionId(?int $versionId)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 * @method string|null getAuthorType()
 * @method void setAuthorType(?string $authorType)
 * @method int|null getGuestId()
 * @method void setGuestId(?int $guestId)
 * @method string|null getAuthorUid()
 * @method void setAuthorUid(?string $authorUid)
 * @method int|null getFrameIn()
 * @method void setFrameIn(?int $frameIn)
 * @method int|null getFrameOut()
 * @method void setFrameOut(?int $frameOut)
 * @method string|null getText()
 * @method void setText(?string $text)
 * @method string|null getDrawing()
 * @method void setDrawing(?string $drawing)
 * @method bool|null getDone()
 * @method void setDone(?bool $done)
 * @method int|null getSubmissionId()
 * @method void setSubmissionId(?int $submissionId)
 * @method int|null getCreatedAt()
 * @method void setCreatedAt(?int $createdAt)
 * @method int|null getUpdatedAt()
 * @method void setUpdatedAt(?int $updatedAt)
 */
class Comment extends Entity {
	public const KUNDE = 'kunde';
	public const BESITZER = 'besitzer';

	protected ?int $versionId = null;
	protected ?int $parentId = null;
	protected ?string $authorType = null;
	protected ?int $guestId = null;
	protected ?string $authorUid = null;
	protected ?int $frameIn = null;
	protected ?int $frameOut = null;
	protected ?string $text = null;
	protected ?string $drawing = null;
	protected ?bool $done = null;
	protected ?int $submissionId = null;
	protected ?int $createdAt = null;
	protected ?int $updatedAt = null;

	public function __construct() {
		$this->addType('versionId', 'integer');
		$this->addType('parentId', 'integer');
		$this->addType('authorType', 'string');
		$this->addType('guestId', 'integer');
		$this->addType('authorUid', 'string');
		$this->addType('frameIn', 'integer');
		$this->addType('frameOut', 'integer');
		$this->addType('text', 'string');
		$this->addType('drawing', 'string');
		$this->addType('done', 'boolean');
		$this->addType('submissionId', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
