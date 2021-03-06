<?php

/**
 * @file classes/submission/seriesEditor/SeriesEditorAction.inc.php
 *
 * Copyright (c) 2003-2011 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SeriesEditorAction
 * @ingroup submission
 *
 * @brief SeriesEditorAction class.
 */



import('classes.submission.common.Action');

class SeriesEditorAction extends Action {

	/**
	 * Constructor.
	 */
	function SeriesEditorAction() {
		parent::Action();
	}

	/**
	 * Actions.
	 */
	/**
	 * Records an editor's submission decision.
	 * @param $seriesEditorSubmission object
	 * @param $decision int
	 */
	function recordDecision($seriesEditorSubmission, $decision) {
		// FIXME #5557 Make sure this works with signoffs
		$signoffDao =& DAORegistry::getDAO('SignoffDAO');
		$signoffs =& $signoffDao->getBySymbolic('SIGNOFF_STAGE', ASSOC_TYPE_MONOGRAPH, $seriesEditorSubmission->getId());
		if (empty($signoffs)) return;

		$seriesEditorSubmissionDao =& DAORegistry::getDAO('SeriesEditorSubmissionDAO');
		$user =& Request::getUser();
		$editorDecision = array(
			'editDecisionId' => null,
			'editorId' => $user->getId(),
			'decision' => $decision,
			'dateDecided' => date(Core::getCurrentDate())
		);

		if (!HookRegistry::call('SeriesEditorAction::recordDecision', array(&$seriesEditorSubmission, $editorDecision))) {
			$seriesEditorSubmission->setStatus(STATUS_QUEUED);
			$seriesEditorSubmission->stampStatusModified();
			$seriesEditorSubmission->addDecision(
									$editorDecision,
									$seriesEditorSubmission->getCurrentReviewType(),
									$seriesEditorSubmission->getCurrentRound()
								);

			$seriesEditorSubmissionDao->updateSeriesEditorSubmission($seriesEditorSubmission);

			$decisions = SeriesEditorSubmission::getEditorDecisionOptions();
			// Add log
			import('classes.monograph.log.MonographLog');
			import('classes.monograph.log.MonographEventLogEntry');
			Locale::requireComponents(array(LOCALE_COMPONENT_APPLICATION_COMMON, LOCALE_COMPONENT_OMP_EDITOR));
			MonographLog::logEvent($seriesEditorSubmission->getId(), MONOGRAPH_LOG_EDITOR_DECISION, MONOGRAPH_LOG_TYPE_EDITOR, $user->getId(), 'log.editor.decision', array('editorName' => $user->getFullName(), 'monographId' => $seriesEditorSubmission->getId(), 'decision' => Locale::translate($decisions[$decision])));
		}
	}

	/**
	 * Assigns a reviewer to a submission.
	 * @param $seriesEditorSubmission object
	 * @param $reviewerId int
	 */
	function addReviewer($seriesEditorSubmission, $reviewerId, $reviewType, $round = null, $reviewDueDate = null, $responseDueDate = null) {
		$seriesEditorSubmissionDao =& DAORegistry::getDAO('SeriesEditorSubmissionDAO');
		$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
		$userDao =& DAORegistry::getDAO('UserDAO');
		$user =& Request::getUser();

		$reviewer =& $userDao->getUser($reviewerId);

		// Check to see if the requested reviewer is not already
		// assigned to review this monograph.
		if ($round == null) {
			$round = $seriesEditorSubmission->getCurrentRound();
		}

		$assigned = $seriesEditorSubmissionDao->reviewerExists($seriesEditorSubmission->getId(), $reviewerId, $reviewType, $round);

		// Only add the reviewer if he has not already
		// been assigned to review this monograph.
		if (!$assigned && isset($reviewer) && !HookRegistry::call('SeriesEditorAction::addReviewer', array(&$seriesEditorSubmission, $reviewerId))) {
			$reviewAssignment = new ReviewAssignment();
			$reviewAssignment->setSubmissionId($seriesEditorSubmission->getId());
			$reviewAssignment->setReviewerId($reviewerId);
			$reviewAssignment->setDateAssigned(Core::getCurrentDate());
			$reviewAssignment->setReviewType($reviewType);
			$reviewAssignment->setRound($round);

			// Assign review form automatically if needed
			$pressId = $seriesEditorSubmission->getPressId();
			$seriesDao =& DAORegistry::getDAO('SeriesDAO');
			$reviewFormDao =& DAORegistry::getDAO('ReviewFormDAO');

			$submissionId = $seriesEditorSubmission->getId();
			$series =& $seriesDao->getById($submissionId, $pressId);

			$seriesEditorSubmission->addReviewAssignment($reviewAssignment, $reviewType, $round);
			$seriesEditorSubmissionDao->updateSeriesEditorSubmission($seriesEditorSubmission);

			$reviewAssignment = $reviewAssignmentDao->getReviewAssignment(
				$seriesEditorSubmission->getId(),
				$reviewerId,
				$round,
				$reviewType
			);

			$press =& Request::getPress();
			$settingsDao =& DAORegistry::getDAO('PressSettingsDAO');
			$settings =& $settingsDao->getPressSettings($press->getId());
			if (isset($reviewDueDate)) SeriesEditorAction::setDueDate($seriesEditorSubmission->getId(), $reviewAssignment->getId(), $reviewDueDate);
			if (isset($responseDueDate)) SeriesEditorAction::setResponseDueDate($seriesEditorSubmission->getId(), $reviewAssignment->getId(), $responseDueDate);

			// Add log
			import('classes.monograph.log.MonographLog');
			import('classes.monograph.log.MonographEventLogEntry');
			MonographLog::logEvent($seriesEditorSubmission->getId(), MONOGRAPH_LOG_REVIEW_ASSIGN, MONOGRAPH_LOG_TYPE_REVIEW, $reviewAssignment->getId(), 'log.review.reviewerAssigned', array('reviewerName' => $reviewer->getFullName(), 'monographId' => $seriesEditorSubmission->getId(), 'reviewType' => $reviewType, 'round' => $round));
		}
	}

	/**
	 * Clears a review assignment from a submission.
	 * @param $seriesEditorSubmission object
	 * @param $reviewId int
	 */
	function clearReview($submissionId, $reviewId) {
		$seriesEditorSubmissionDao =& DAORegistry::getDAO('SeriesEditorSubmissionDAO');
		$seriesEditorSubmission =& $seriesEditorSubmissionDao->getSeriesEditorSubmission($submissionId);
		$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
		$userDao =& DAORegistry::getDAO('UserDAO');
		$user =& Request::getUser();

		$reviewAssignment =& $reviewAssignmentDao->getById($reviewId);

		if (isset($reviewAssignment) && $reviewAssignment->getSubmissionId() == $seriesEditorSubmission->getId() && !HookRegistry::call('SeriesEditorAction::clearReview', array(&$seriesEditorSubmission, $reviewAssignment))) {
			$reviewer =& $userDao->getUser($reviewAssignment->getReviewerId());
			if (!isset($reviewer)) return false;
			$seriesEditorSubmission->removeReviewAssignment($reviewId);
			$seriesEditorSubmissionDao->updateSeriesEditorSubmission($seriesEditorSubmission);

			// Add log
			import('classes.monograph.log.MonographLog');
			import('classes.monograph.log.MonographEventLogEntry');
			MonographLog::logEvent($seriesEditorSubmission->getId(), MONOGRAPH_LOG_REVIEW_CLEAR, MONOGRAPH_LOG_TYPE_REVIEW, $reviewAssignment->getId(), 'log.review.reviewCleared', array('reviewerName' => $reviewer->getFullName(), 'monographId' => $seriesEditorSubmission->getId(), 'reviewType' => $reviewAssignment->getReviewType(), 'round' => $reviewAssignment->getRound()));

			return true;
		} else return false;
	}

	/**
	 * Sets the due date for a review assignment.
	 * @param $monographId int
	 * @param $reviewId int
	 * @param $dueDate string
	 * @param $numWeeks int
	 * @param $logEntry boolean
	 */
	function setDueDate($monographId, $reviewId, $dueDate = null, $numWeeks = null, $logEntry = false) {
		$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
		$userDao =& DAORegistry::getDAO('UserDAO');
		$user =& Request::getUser();

		$reviewAssignment =& $reviewAssignmentDao->getById($reviewId);
		$reviewer =& $userDao->getUser($reviewAssignment->getReviewerId());
		if (!isset($reviewer)) return false;

		if ($reviewAssignment->getSubmissionId() == $monographId && !HookRegistry::call('SeriesEditorAction::setDueDate', array(&$reviewAssignment, &$reviewer, &$dueDate, &$numWeeks))) {
			$today = getDate();
			$todayTimestamp = mktime(0, 0, 0, $today['mon'], $today['mday'], $today['year']);
			if ($dueDate != null) {
				$dueDateParts = explode('-', $dueDate);

				// Ensure that the specified due date is today or after today's date.
				if ($todayTimestamp <= strtotime($dueDate)) {
					$reviewAssignment->setDateDue(date('Y-m-d H:i:s', mktime(0, 0, 0, $dueDateParts[1], $dueDateParts[2], $dueDateParts[0])));
				} else {
					$reviewAssignment->setDateDue(date('Y-m-d H:i:s', $todayTimestamp));
				}
			} else {
				// Add the equivilant of $numWeeks weeks, measured in seconds, to $todaysTimestamp.
				$numWeeks = max((int) $numWeeks, 2);
				$newDueDateTimestamp = $todayTimestamp + ($numWeeks * 7 * 24 * 60 * 60);
				$reviewAssignment->setDateDue(date('Y-m-d H:i:s', $newDueDateTimestamp));
			}

			$reviewAssignment->stampModified();
			$reviewAssignmentDao->updateObject($reviewAssignment);

			if ($logEntry) {
				// Add log
				import('classes.monograph.log.MonographLog');
				import('classes.monograph.log.MonographEventLogEntry');
				MonographLog::logEvent(
					$monographId,
					MONOGRAPH_LOG_REVIEW_SET_DUE_DATE,
					MONOGRAPH_LOG_TYPE_REVIEW,
					$reviewAssignment->getId(),
					'log.review.reviewDueDateSet',
					array(
						'reviewerName' => $reviewer->getFullName(),
						'dueDate' => strftime(Config::getVar('general', 'date_format_short'),
						strtotime($reviewAssignment->getDateDue())),
						'monographId' => $monographId,
						'reviewType' => $reviewAssignment->getReviewType(),
						'round' => $reviewAssignment->getRound()
					)
				);
			}
		}
	}

	/**
	 * Sets the due date for a reviewer to respond to a review request.
	 * @param $monographId int
	 * @param $reviewId int
	 * @param $dueDate string
	 */
	function setResponseDueDate($monographId, $reviewId, $dueDate = null, $numWeeks = null) {
		$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
		$userDao =& DAORegistry::getDAO('UserDAO');
		$user =& Request::getUser();

		$reviewAssignment =& $reviewAssignmentDao->getById($reviewId);
		$reviewer =& $userDao->getUser($reviewAssignment->getReviewerId());
		if (!isset($reviewer)) return false;

		if ($reviewAssignment->getSubmissionId() == $monographId && !HookRegistry::call('SeriesEditorAction::setDueDate', array(&$reviewAssignment, &$reviewer, &$dueDate, &$numWeeks))) {
			$today = getDate();
			$todayTimestamp = mktime(0, 0, 0, $today['mon'], $today['mday'], $today['year']);
			if ($dueDate != null) {
				$dueDateParts = explode('-', $dueDate);

				// Ensure that the specified due date is today or after today's date.
				if ($todayTimestamp <= strtotime($dueDate)) {
					$reviewAssignment->setDateDue(date('Y-m-d H:i:s', mktime(0, 0, 0, $dueDateParts[1], $dueDateParts[2], $dueDateParts[0])));
				} else {
					$reviewAssignment->setDateDue(date('Y-m-d H:i:s', $todayTimestamp));
				}
			} else {
				// Add the equivilant of $numWeeks weeks, measured in seconds, to $todaysTimestamp.
				$numWeeks = max((int) $numWeeks, 2);
				$newDueDateTimestamp = $todayTimestamp + ($numWeeks * 7 * 24 * 60 * 60);
				$reviewAssignment->setDateDue(date('Y-m-d H:i:s', $newDueDateTimestamp));
			}

			$reviewAssignment->stampModified();
			$reviewAssignmentDao->updateObject($reviewAssignment);
		}
	}

	/**
	 * Get the text of all peer reviews for a submission
	 * @param $seriesEditorSubmission SeriesEditorSubmission
	 * @return string
	 */
	function getPeerReviews($seriesEditorSubmission) {
		$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
		$monographCommentDao =& DAORegistry::getDAO('MonographCommentDAO');
		$reviewFormResponseDao =& DAORegistry::getDAO('ReviewFormResponseDAO');
		$reviewFormElementDao =& DAORegistry::getDAO('ReviewFormElementDAO');

		$reviewAssignments =& $reviewAssignmentDao->getBySubmissionId($seriesEditorSubmission->getId(), $seriesEditorSubmission->getCurrentRound());
		$reviewIndexes =& $reviewAssignmentDao->getReviewIndexesForRound($seriesEditorSubmission->getId(), $seriesEditorSubmission->getCurrentRound());
		Locale::requireComponents(array(LOCALE_COMPONENT_PKP_SUBMISSION));

		$body = '';
		$textSeparator = "------------------------------------------------------";
		foreach ($reviewAssignments as $reviewAssignment) {
			// If the reviewer has completed the assignment, then import the review.
			if ($reviewAssignment->getDateCompleted() != null && !$reviewAssignment->getCancelled()) {
				// Get the comments associated with this review assignment
				$monographComments =& $monographCommentDao->getMonographComments($seriesEditorSubmission->getId(), COMMENT_TYPE_PEER_REVIEW, $reviewAssignment->getId());

				if($monographComments) {
					$body .= "\n\n$textSeparator\n";
					$body .= Locale::translate('submission.comments.importPeerReviews.reviewerLetter', array('reviewerLetter' => String::enumerateAlphabetically($reviewIndexes[$reviewAssignment->getId()]))) . "\n";
					if (is_array($monographComments)) {
						foreach ($monographComments as $comment) {
							// If the comment is viewable by the author, then add the comment.
							if ($comment->getViewable()) {
								$body .= String::html2text($comment->getComments()) . "\n\n";
							}
						}
					}
					$body .= "$textSeparator\n\n";
				}
				if ($reviewFormId = $reviewAssignment->getReviewFormId()) {
					$reviewId = $reviewAssignment->getId();


					$reviewFormElements =& $reviewFormElementDao->getReviewFormElements($reviewFormId);
					if(!$monographComments) {
						$body .= "$textSeparator\n";

						$body .= Locale::translate('submission.comments.importPeerReviews.reviewerLetter', array('reviewerLetter' => String::enumerateAlphabetically($reviewIndexes[$reviewAssignment->getId()]))) . "\n\n";
					}
					foreach ($reviewFormElements as $reviewFormElement) {
						$body .= String::html2text($reviewFormElement->getLocalizedQuestion()) . ": \n";
						$reviewFormResponse = $reviewFormResponseDao->getReviewFormResponse($reviewId, $reviewFormElement->getId());

						if ($reviewFormResponse) {
							$possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
							if (in_array($reviewFormElement->getElementType(), $reviewFormElement->getMultipleResponsesElementTypes())) {
								if ($reviewFormElement->getElementType() == REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
									foreach ($reviewFormResponse->getValue() as $value) {
										$body .= "\t" . String::htmltext($possibleResponses[$value-1]['content']) . "\n";
									}
								} else {
									$body .= "\t" . String::html2text($possibleResponses[$reviewFormResponse->getValue()-1]['content']) . "\n";
								}
								$body .= "\n";
							} else {
								$body .= "\t" . String::html2text($reviewFormResponse->getValue()) . "\n\n";
							}
						}

					}
					$body .= "$textSeparator\n\n";

				}


			}
		}

		return $body;
	}
}

?>
