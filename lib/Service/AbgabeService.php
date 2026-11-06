<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Service;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\Comment;
use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\Guest;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Db\Submission;
use OCA\Clipproof\Db\SubmissionMapper;
use OCA\Clipproof\Exception\KommentarException;
use OCA\Clipproof\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Ein Klick sendet alle Entwürfe eines Gastes in diesem Review ab. Das
 * ist eine Abgabe; der Besitzer bekommt dazu eine Benachrichtigung.
 * Keine Automatik: Abgesendet wird nur, was der Kunde abschickt.
 */
class AbgabeService {
	private const AUSZUG_MAX = 200;

	public function __construct(
		private CommentMapper $comments,
		private ReviewVersionMapper $versions,
		private SubmissionMapper $submissions,
		private IDBConnection $db,
		private ITimeFactory $time,
		private IManager $benachrichtigungen,
		private LoggerInterface $logger,
	) {
	}

	/** @throws KommentarException */
	public function abgeben(Review $review, Guest $gast): Submission {
		if ($review->isCommentsLocked()) {
			throw new KommentarException('This video has been approved. Comments are no longer possible.', 423);
		}
		$versionen = [];
		foreach ($this->versions->findForReview((int)$review->getId()) as $v) {
			$versionen[(int)$v->getId()] = $v;
		}
		$entwuerfe = $this->comments->findEntwuerfe((int)$gast->getId(), array_keys($versionen));
		if ($entwuerfe === []) {
			throw new KommentarException('There are no comments to send.', 409);
		}

		// Die erste Anmerkung im Video, in der neuesten kommentierten Version.
		usort($entwuerfe, fn (Comment $a, Comment $b) => [$versionen[$b->getVersionId()]->getNumber(), $a->getFrameIn(), $a->getId()]
			<=> [$versionen[$a->getVersionId()]->getNumber(), $b->getFrameIn(), $b->getId()]);
		$erste = $entwuerfe[0];
		$version = $versionen[(int)$erste->getVersionId()];
		$jetzt = $this->time->getTime();
		$text = self::protokolltext($review, $version, $erste, count($entwuerfe), $jetzt);

		$this->db->beginTransaction();
		try {
			$abgabe = new Submission();
			$abgabe->setReviewId($review->getId());
			$abgabe->setVersionId($version->getId());
			$abgabe->setGuestId($gast->getId());
			$abgabe->setCommentCount(count($entwuerfe));
			$abgabe->setProtocolText($text);
			$abgabe->setCreatedAt($jetzt);
			$abgabe = $this->submissions->insert($abgabe);

			// Bedingt zuordnen: Hat ein paralleler Klick die Entwürfe schon
			// genommen, gibt es keine zweite Abgabe.
			$ids = array_map(fn (Comment $k) => (int)$k->getId(), $entwuerfe);
			if ($this->comments->abgabeZuordnen($ids, (int)$abgabe->getId(), $jetzt) !== count($ids)) {
				// Der catch unten rollt zurück; die Abgabe entsteht nicht.
				throw new KommentarException('These comments have already been sent.', 409);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->benachrichtigen($review, $version, $abgabe, $erste, $gast);
		return $abgabe;
	}

	/** Eine Benachrichtigung je Abgabe an den Besitzer. Scheitert sie, bleibt die Abgabe gültig. */
	private function benachrichtigen(Review $review, ReviewVersion $version, Submission $abgabe, Comment $erste, Guest $gast): void {
		try {
			$auszug = trim(preg_replace('/\s+/u', ' ', (string)$erste->getText()) ?? '');
			if (mb_strlen($auszug) > self::AUSZUG_MAX) {
				$auszug = rtrim(mb_substr($auszug, 0, self::AUSZUG_MAX - 1)) . '…';
			}
			$n = $this->benachrichtigungen->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser((string)$review->getOwnerUid())
				->setDateTime(new \DateTime('@' . (int)$abgabe->getCreatedAt()))
				->setObject('review', (string)$review->getId())
				->setSubject(Notifier::ABGABE, [
					'gast' => (string)$gast->getName(),
					'anzahl' => (int)$abgabe->getCommentCount(),
					'titel' => (string)$review->getTitle(),
					'version' => (int)$version->getNumber(),
					'erste' => self::timecode($version, (int)$erste->getFrameIn()) . ($auszug !== '' ? ' ' . $auszug : ''),
				]);
			$this->benachrichtigungen->notify($n);
		} catch (\Throwable $e) {
			$this->logger->warning('Benachrichtigung zur Abgabe {id} gescheitert: {fehler}', ['id' => $abgabe->getId(), 'fehler' => $e->getMessage(), 'app' => Application::APP_ID]);
		}
	}

	/**
	 * Der Text ist der Beleg der Abgabe, der im Archiv bleibt, auch wenn
	 * das Arbeitsmaterial gelöscht ist. Er muss für sich allein
	 * verständlich sein: Review, Version, Datum, Anzahl, erste
	 * Anmerkung mit Timecode und Frame. Immer länger als fünf Zeichen.
	 * Gespeichert, nicht angezeigt: deshalb englisch und in UTC, unabhängig von Sprache und Zeitzone.
	 */
	public static function protokolltext(Review $review, ReviewVersion $version, Comment $erste, int $anzahl, int $zeitpunkt): string {
		$tc = self::timecode($version, (int)$erste->getFrameIn());
		$auszug = trim(preg_replace('/\s+/u', ' ', (string)$erste->getText()) ?? '');
		if ($auszug === '') {
			$auszug = '(drawing)';
		} elseif (mb_strlen($auszug) > self::AUSZUG_MAX) {
			$auszug = rtrim(mb_substr($auszug, 0, self::AUSZUG_MAX - 1)) . '…';
		}
		$datum = gmdate('Y-m-d H:i', $zeitpunkt) . ' UTC';
		return sprintf(
			'Review "%s" version %d, sent %s, %d %s. First at %s (frame %d): "%s"',
			$review->getTitle(),
			$version->getNumber(),
			$datum,
			$anzahl,
			$anzahl === 1 ? 'comment' : 'comments',
			$tc,
			$erste->getFrameIn(),
			$auszug,
		);
	}

	private static function timecode(ReviewVersion $version, int $frame): string {
		return Timecode::vonFrames((int)$version->getStartTcFrames() + $frame, (int)$version->getFpsNum(), (int)$version->getFpsDen(), $version->isDropFrame());
	}

}
