<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\CommentMapper;
use OCA\Clipproof\Db\Review;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Db\Submission;
use OCA\Clipproof\Db\SubmissionMapper;
use OCA\Clipproof\Exception\KommentarException;
use OCA\Clipproof\Exception\TokenInvalidException;
use OCA\Clipproof\Service\AbgabeService;
use OCA\Clipproof\Service\CommentService;
use OCA\Clipproof\Service\GuestService;
use OCA\Clipproof\Service\KommentarAnsicht;
use OCA\Clipproof\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IL10N;

/**
 * Anmerkungen auf der Kundenseite. Anmeldung über das Token in der
 * Adresse und den Gastschlüssel in der Kopfzeile X-Clipproof-Gast,
 * keine Cookies. Deshalb keine CSRF-Angriffsfläche: Ein fremder Server
 * kann diese Kopfzeile nicht setzen.
 */
class PublicKommentarController extends Controller {
	public function __construct(
		IRequest $request,
		private TokenService $tokens,
		private GuestService $gaeste,
		private CommentService $kommentare,
		private AbgabeService $abgaben,
		private CommentMapper $commentMapper,
		private ReviewVersionMapper $versionMapper,
		private SubmissionMapper $submissionMapper,
		private KommentarAnsicht $ansicht,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_token')]
	#[AnonRateLimit(limit: 10, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/r/{token}/gast')]
	public function gast(string $token, string $name = ''): JSONResponse {
		return $this->mitReview($token, function (Review $review) use ($name) {
			[$gast, $schluessel] = $this->gaeste->anmelden($review, $name);
			return new JSONResponse(['name' => $gast->getName(), 'schluessel' => $schluessel], Http::STATUS_CREATED);
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_token')]
	#[FrontpageRoute(verb: 'GET', url: '/r/{token}/kommentare')]
	public function liste(string $token): JSONResponse {
		return $this->mitReview($token, function (Review $review) {
			$gast = $this->gaeste->finden($review, $this->schluessel());
			$versionen = $this->versionen($review);
			$liste = $this->commentMapper->findSichtbarFuerGast(array_keys($versionen), $gast?->getId());
			$daten = $this->ansicht->liste($liste, $versionen, $this->abgabenNachId($review), $gast);
			return new JSONResponse([
				'gast' => $gast !== null ? ['name' => $gast->getName()] : null,
				'kommentare' => $daten,
				'entwuerfe' => count(array_filter($daten, fn (array $k) => $k['entwurf'] && $k['eigen'])),
				'gesperrt' => $review->isCommentsLocked(),
			]);
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_token')]
	#[AnonRateLimit(limit: 60, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/r/{token}/kommentare')]
	public function anlegen(string $token, int $versionId, int $frameIn, ?int $frameOut = null, string $text = '', ?string $zeichnung = null, ?int $parentId = null): JSONResponse {
		return $this->mitReview($token, function (Review $review) use ($versionId, $frameIn, $frameOut, $text, $zeichnung, $parentId) {
			$gast = $this->gaeste->pflicht($review, $this->schluessel());
			$k = $this->kommentare->entwurfAnlegen($review, $gast, [
				'versionId' => $versionId, 'frameIn' => $frameIn, 'frameOut' => $frameOut,
				'text' => $text, 'zeichnung' => $zeichnung, 'parentId' => $parentId,
			]);
			return new JSONResponse($this->ansicht->liste([$k], $this->versionen($review), [], $gast)[0], Http::STATUS_CREATED);
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_token')]
	#[AnonRateLimit(limit: 120, period: 300)]
	#[FrontpageRoute(verb: 'PATCH', url: '/r/{token}/kommentare/{id}')]
	public function aendern(string $token, int $id): JSONResponse {
		$felder = array_intersect_key($this->request->getParams(), array_flip(['text', 'zeichnung', 'frameIn', 'frameOut']));
		return $this->mitReview($token, function (Review $review) use ($id, $felder) {
			$gast = $this->gaeste->pflicht($review, $this->schluessel());
			if (isset($felder['frameIn'])) {
				$felder['frameIn'] = (int)$felder['frameIn'];
				$felder['frameOut'] = isset($felder['frameOut']) ? (int)$felder['frameOut'] : null;
			}
			$k = $this->kommentare->entwurfAendern($review, $gast, $id, $felder);
			return new JSONResponse($this->ansicht->liste([$k], $this->versionen($review), [], $gast)[0]);
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_token')]
	#[AnonRateLimit(limit: 120, period: 300)]
	#[FrontpageRoute(verb: 'DELETE', url: '/r/{token}/kommentare/{id}')]
	public function loeschen(string $token, int $id): JSONResponse {
		return $this->mitReview($token, function (Review $review) use ($id) {
			$this->kommentare->entwurfLoeschen($review, $this->gaeste->pflicht($review, $this->schluessel()), $id);
			return new JSONResponse(null, Http::STATUS_NO_CONTENT);
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_token')]
	#[AnonRateLimit(limit: 60, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/r/{token}/abgaben')]
	public function abgeben(string $token): JSONResponse {
		return $this->mitReview($token, function (Review $review) {
			$abgabe = $this->abgaben->abgeben($review, $this->gaeste->pflicht($review, $this->schluessel()));
			return new JSONResponse([
				'anzahl' => $abgabe->getCommentCount(),
				'abgesendetAm' => gmdate('Y-m-d\TH:i:s\Z', (int)$abgabe->getCreatedAt()),
			], Http::STATUS_CREATED);
		});
	}

	/**
	 * Token prüfen, Fehler einheitlich beantworten.
	 *
	 * @param callable(Review): JSONResponse $tun
	 */
	private function mitReview(string $token, callable $tun): JSONResponse {
		try {
			$antwort = $tun($this->tokens->resolve($token));
		} catch (TokenInvalidException $e) {
			$antwort = new JSONResponse(['fehler' => $this->l->t('This link is not valid'), 'code' => 'link_invalid'], Http::STATUS_NOT_FOUND);
			$antwort->throttle(['grund' => $e->getReason()]);
		} catch (KommentarException $e) {
			$antwort = new JSONResponse(['fehler' => $e->meldung($this->l)], $e->getStatus());
		}
		$antwort->addHeader('Referrer-Policy', 'no-referrer');
		return $antwort;
	}

	private function schluessel(): ?string {
		$wert = $this->request->getHeader(GuestService::KOPFZEILE);
		return $wert !== '' ? $wert : null;
	}

	/** @return array<int, ReviewVersion> */
	private function versionen(Review $review): array {
		$out = [];
		foreach ($this->versionMapper->findForReview((int)$review->getId()) as $v) {
			$out[(int)$v->getId()] = $v;
		}
		return $out;
	}

	/** @return array<int, Submission> */
	private function abgabenNachId(Review $review): array {
		$out = [];
		foreach ($this->submissionMapper->findForReview((int)$review->getId()) as $s) {
			$out[(int)$s->getId()] = $s;
		}
		return $out;
	}
}
