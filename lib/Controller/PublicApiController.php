<?php

// SPDX-FileCopyrightText: 2026 Schauties Media
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Clipproof\Controller;

use OCA\Clipproof\AppInfo\Application;
use OCA\Clipproof\Db\ReviewVersion;
use OCA\Clipproof\Db\ReviewVersionMapper;
use OCA\Clipproof\Exception\TokenInvalidException;
use OCA\Clipproof\Http\RangeStreamResponse;
use OCA\Clipproof\Service\RangeStream;
use OCA\Clipproof\Service\VideoAdresse;
use OCA\Clipproof\Service\ReviewService;
use OCA\Clipproof\Service\Timecode;
use OCA\Clipproof\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IL10N;

/**
 * Daten und Video für die Kundenseite. Anmeldung allein über das Token in
 * der URL, keine Cookies: Safari lässt im eingebetteten Rahmen keine
 * Cookies der eingebetteten Nextcloud zu. Ohne Cookie-Anmeldung gibt es auch
 * keine CSRF-Angriffsfläche.
 */
class PublicApiController extends Controller {
	public function __construct(
		IRequest $request,
		private TokenService $tokens,
		private ReviewVersionMapper $versions,
		private ReviewService $reviews,
		private ISession $session,
		private IURLGenerator $urls,
		private VideoAdresse $videoAdresse,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_token')]
	#[FrontpageRoute(verb: 'GET', url: '/r/{token}/daten')]
	public function daten(string $token): JSONResponse {
		try {
			$review = $this->tokens->resolve($token);
		} catch (TokenInvalidException $e) {
			return $this->abgewiesen($e);
		}
		$versionen = array_map(fn (ReviewVersion $v) => [
			'id' => $v->getId(),
			'nummer' => $v->getNumber(),
			'fpsNum' => $v->getFpsNum(),
			'fpsDen' => $v->getFpsDen(),
			'df' => $v->isDropFrame(),
			'startFrames' => $v->getStartTcFrames(),
			'startTimecode' => Timecode::vonFrames($v->getStartTcFrames(), $v->getFpsNum(), $v->getFpsDen(), $v->isDropFrame()),
			'bilder' => $v->getDurationFrames(),
			'breite' => $v->getWidth(),
			'hoehe' => $v->getHeight(),
		] + $this->videoAdresse->erzeugen($review, $v), $this->versions->findForReview($review->getId()));

		return $this->ohneReferer(new JSONResponse([
			'titel' => $review->getTitle(),
			'anrede' => $review->getAnrede(),
			'kommentareGesperrt' => $review->isCommentsLocked(),
			'versionen' => $versionen,
		]));
	}

	/**
	 * Video über eine kurzlebige, signierte Adresse ohne Review-Token
	 * (VideoAdresse). Abgelaufen oder gefälscht: 403, gedrosselt.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'clipproof_video')]
	#[FrontpageRoute(verb: 'GET', url: '/v/{versionId}/{ablauf}/{signatur}', requirements: ['versionId' => '\\d+', 'ablauf' => '\\d+'])]
	public function videoSigniert(int $versionId, int $ablauf, string $signatur): Response {
		try {
			$version = $this->videoAdresse->pruefen($versionId, $ablauf, $signatur);
		} catch (TokenInvalidException $e) {
			$antwort = new JSONResponse(['fehler' => $this->l->t('Address expired'), 'code' => 'address_expired'], Http::STATUS_FORBIDDEN);
			// Abgelaufene Adressen sind normal (Tab lange offen): nur
			// Fälschungen und Unbekanntes drosseln.
			if ($e->getReason() !== TokenInvalidException::ABGELAUFEN) {
				$antwort->throttle(['grund' => $e->getReason()]);
			}
			return $this->ohneReferer($antwort);
		}
		// Eine laufende Videoanfrage darf die übrigen Anfragen desselben
		// Browsers nicht an der Sitzungssperre aufhalten.
		$this->session->close();

		try {
			$datei = $this->reviews->dateiDerVersion($version);
			$groesse = $datei->getSize();
			$spanne = RangeStream::parse($this->request->getHeader('Range'), $groesse);
			if ($spanne === RangeStream::UNERFUELLBAR) {
				$antwort = new Response();
				$antwort->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
				$antwort->addHeader('Content-Range', 'bytes */' . $groesse);
				return $antwort;
			}
			if ($spanne === null) {
				$spanne = RangeStream::ohneAnfrage($groesse);
			}
			$strom = $datei->fopen('rb');
			if ($strom === false) {
				throw new NotFoundException('File not readable');
			}
		} catch (DoesNotExistException|NotFoundException) {
			return new JSONResponse(['fehler' => $this->l->t('Not found.')], Http::STATUS_NOT_FOUND);
		}

		[$start, $ende] = is_array($spanne) ? $spanne : [0, $groesse - 1];
		return $this->ohneReferer(new RangeStreamResponse(
			$strom,
			$start,
			$ende - $start + 1,
			$groesse,
			$datei->getMimeType(),
			is_array($spanne),
			$datei->getEtag(),
			$datei->getMTime(),
			$this->request->getMethod() === 'HEAD',
		));
	}

	private function abgewiesen(TokenInvalidException $e): JSONResponse {
		$antwort = new JSONResponse(['fehler' => $this->l->t('This link is not valid'), 'code' => 'link_invalid'], Http::STATUS_NOT_FOUND);
		$antwort->throttle(['grund' => $e->getReason()]);
		return $this->ohneReferer($antwort);
	}

	/**
	 * Das Token steht in der Adresse: nie als Referer an fremde Server.
	 * Nextcloud setzt das meist schon selbst, aber nicht in jeder
	 * Serverkonfiguration.
	 *
	 * @template T of Response
	 * @param T $antwort
	 * @return T
	 */
	private function ohneReferer(Response $antwort): Response {
		$antwort->addHeader('Referrer-Policy', 'no-referrer');
		return $antwort;
	}
}
