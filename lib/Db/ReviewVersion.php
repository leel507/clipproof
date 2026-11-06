<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ein Schnitt eines Reviews. Die Bildrate steht als Bruch und wird nie
 * geraten: Sie kommt aus dem Container und wird vom Besitzer bestätigt.
 *
 * @method int getReviewId()
 * @method void setReviewId(int $reviewId)
 * @method int getNumber()
 * @method void setNumber(int $number)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileOwnerUid()
 * @method void setFileOwnerUid(string $fileOwnerUid)
 * @method int getFpsNum()
 * @method void setFpsNum(int $fpsNum)
 * @method int getFpsDen()
 * @method void setFpsDen(int $fpsDen)
 * @method string getFpsSource()
 * @method void setFpsSource(string $fpsSource)
 * @method bool|null getDropFrame()
 * @method void setDropFrame(?bool $dropFrame)
 * @method int getStartTcFrames()
 * @method void setStartTcFrames(int $startTcFrames)
 * @method int getDurationFrames()
 * @method void setDurationFrames(int $durationFrames)
 * @method int|null getWidth()
 * @method void setWidth(?int $width)
 * @method int|null getHeight()
 * @method void setHeight(?int $height)
 * @method bool|null getFileMissing()
 * @method void setFileMissing(?bool $fileMissing)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class ReviewVersion extends Entity {
	public const FPS_CONTAINER = 'container';
	public const FPS_EINGABE = 'eingabe';

	protected ?int $reviewId = null;
	protected ?int $number = null;
	protected ?int $fileId = null;
	protected ?string $fileOwnerUid = null;
	protected ?int $fpsNum = null;
	protected ?int $fpsDen = null;
	protected ?string $fpsSource = null;
	protected ?bool $dropFrame = null;
	protected ?int $startTcFrames = null;
	protected ?int $durationFrames = null;
	protected ?int $width = null;
	protected ?int $height = null;
	protected ?bool $fileMissing = null;
	protected ?int $createdAt = null;

	public function __construct() {
		$this->addType('reviewId', 'integer');
		$this->addType('number', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('fileOwnerUid', 'string');
		$this->addType('fpsNum', 'integer');
		$this->addType('fpsDen', 'integer');
		$this->addType('fpsSource', 'string');
		$this->addType('dropFrame', 'boolean');
		$this->addType('startTcFrames', 'integer');
		$this->addType('durationFrames', 'integer');
		$this->addType('width', 'integer');
		$this->addType('height', 'integer');
		$this->addType('fileMissing', 'boolean');
		$this->addType('createdAt', 'integer');
	}

	public function isDropFrame(): bool {
		return $this->dropFrame === true;
	}
}
