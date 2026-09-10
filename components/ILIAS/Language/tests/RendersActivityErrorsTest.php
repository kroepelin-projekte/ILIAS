<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);

namespace ILIAS\Language;

use ILIAS\Language\Activities\AmbiguousLanguageTitleException;
use ILIAS\Language\Activities\InvalidInputException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RendersActivityErrors trait itself (see its own class
 * docblock), in isolation from any concrete GUI class - via
 * RendersActivityErrorsTestHost, a minimal class that only mixes in the
 * trait and exposes activityErrorMessage() through a public wrapper.
 */
class RendersActivityErrorsTest extends TestCase
{
    private function host(\ilLogger $logger, \ilLanguage|\PHPUnit\Framework\MockObject\MockObject $lng): RendersActivityErrorsTestHost
    {
        return new RendersActivityErrorsTestHost($logger, $lng);
    }

    private function languageMockReturningTopicAsIs(): \ilLanguage
    {
        $lng = $this->createMock(\ilLanguage::class);
        $lng->method('txt')->willReturnArgument(0);

        return $lng;
    }

    public function testAPlainStringErrorIsReturnedAsIsAndNeverLogged(): void
    {
        $logger = $this->createMock(\ilLogger::class);
        $logger->expects($this->never())->method('error');

        $message = $this->host($logger, $this->languageMockReturningTopicAsIs())
            ->callActivityErrorMessage('msg_no_perm_write');

        $this->assertSame('msg_no_perm_write', $message);
    }

    public function testASafeToDisplayActivityErrorMessageIsReturnedAsIsAndNeverLogged(): void
    {
        $logger = $this->createMock(\ilLogger::class);
        $logger->expects($this->never())->method('error');

        $error = new InvalidInputException('language_keys: not_min_length');

        $message = $this->host($logger, $this->languageMockReturningTopicAsIs())
            ->callActivityErrorMessage($error);

        $this->assertSame('language_keys: not_min_length', $message);
    }

    public function testAnAmbiguousLanguageTitleExceptionIsAlsoTreatedAsSafeToDisplayAndNeverLogged(): void
    {
        $logger = $this->createMock(\ilLogger::class);
        $logger->expects($this->never())->method('error');

        $error = new AmbiguousLanguageTitleException('Multiple language objects share the title(s) "fr".');

        $message = $this->host($logger, $this->languageMockReturningTopicAsIs())
            ->callActivityErrorMessage($error);

        $this->assertSame('Multiple language objects share the title(s) "fr".', $message);
    }

    /**
     * A non-SafeToDisplayActivityError \Throwable must never be shown
     * directly - its message is logged instead, and a generic, localized
     * text ('action_aborted') is returned.
     */
    public function testANonSafeToDisplayThrowableIsLoggedAndAGenericMessageIsReturned(): void
    {
        $logger = $this->createMock(\ilLogger::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('boom')
        );

        $message = $this->host($logger, $this->languageMockReturningTopicAsIs())
            ->callActivityErrorMessage(new \RuntimeException('boom'));

        $this->assertSame('action_aborted', $message);
    }

    /**
     * Regression test for describeThrowableChain(): a wrapper exception
     * (e.g. the \RuntimeException/InvalidInputException GrindsFormInput::grind()
     * wraps a lower-level \Throwable in) must never hide the original
     * failure's own class and message from the log - the FULL cause chain
     * (every getPrevious() link) must be logged, not just the outermost
     * wrapper.
     */
    public function testANonSafeToDisplayThrowableWithAPreviousExceptionLogsTheFullCauseChain(): void
    {
        $root_cause = new \PDOException('duplicate key value violates unique constraint');
        $wrapper = new \RuntimeException('failed to write language entry', 0, $root_cause);

        $logged = [];
        $logger = $this->createMock(\ilLogger::class);
        $logger->expects($this->once())->method('error')->willReturnCallback(
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );

        $message = $this->host($logger, $this->languageMockReturningTopicAsIs())
            ->callActivityErrorMessage($wrapper);

        $this->assertSame('action_aborted', $message);
        $this->assertCount(1, $logged);
        $logged_message = $logged[0];

        // Both the outer wrapper AND the inner root cause (class + message)
        // must appear in the logged string - a wrapper must never hide the
        // original failure.
        $this->assertStringContainsString(\RuntimeException::class . ': failed to write language entry', $logged_message);
        $this->assertStringContainsString(\PDOException::class . ': duplicate key value violates unique constraint', $logged_message);

        // The two descriptions must be visibly separated ("Caused by:"),
        // not merely both present anywhere in the string by coincidence.
        $this->assertStringContainsString("Caused by:\n", $logged_message);
        $this->assertLessThan(
            strpos($logged_message, \PDOException::class . ': duplicate key value violates unique constraint'),
            strpos($logged_message, \RuntimeException::class . ': failed to write language entry')
        );
    }

    /**
     * A THREE-deep chain must have every link (not just the first two)
     * logged - describeThrowableChain() recurses via getPrevious() until it
     * returns null, not just once.
     */
    public function testAThreeDeepCauseChainLogsEveryLink(): void
    {
        $innermost = new \InvalidArgumentException('column "xx" does not exist');
        $middle = new \PDOException('query failed', 0, $innermost);
        $outer = new \RuntimeException('database operation failed', 0, $middle);

        $logged = [];
        $logger = $this->createMock(\ilLogger::class);
        $logger->method('error')->willReturnCallback(
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );

        $this->host($logger, $this->languageMockReturningTopicAsIs())->callActivityErrorMessage($outer);

        $this->assertCount(1, $logged);
        $logged_message = $logged[0];

        $this->assertStringContainsString(\RuntimeException::class . ': database operation failed', $logged_message);
        $this->assertStringContainsString(\PDOException::class . ': query failed', $logged_message);
        $this->assertStringContainsString(
            \InvalidArgumentException::class . ': column "xx" does not exist',
            $logged_message
        );
    }
}

/**
 * Minimal host for RendersActivityErrors: only mixes in the trait, wires up
 * its two required collaborators via the constructor (rather than the
 * `assign exactly once, in your own constructor` idiom every real GUI class
 * uses), and exposes activityErrorMessage() through a public wrapper.
 */
final class RendersActivityErrorsTestHost
{
    use RendersActivityErrors;

    public function __construct(
        \ilLogger $activity_error_logger,
        private readonly \ilLanguage $lng,
    ) {
        $this->activity_error_logger = $activity_error_logger;
    }

    public function callActivityErrorMessage(\Throwable|string $error): string
    {
        return $this->activityErrorMessage($error);
    }
}
