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

namespace ILIAS\LearningSequence\Player;

use ILIAS\UI\Factory;
use ILIAS\UI\Component\Component;
use ILIAS\UI\Component\Card\Card;

/**
 * Reusable "template" for the adaptive branch-selection page.
 *
 * When the learner reaches a branch (more than one allowed successor), this
 * builder renders one card ("box") per selectable object using ILIAS UI
 * (Kitchen Sink) components: the object icon, its title, its description and a
 * button that starts the object. The cards are laid out as a responsive deck
 * underneath a short, friendly headline.
 *
 * The rendering is deliberately encapsulated here (and not in the player) so it
 * can be reused later, e.g. by the map view or wherever an object choice needs
 * to be presented.
 */
class LSChoicePageBuilder
{
    public const HEADLINE = 'Welches Objekt möchten Sie als Nächstes bearbeiten?';
    public const SUBLINE = 'Wählen Sie eines der folgenden Objekte aus, um Ihren Weg fortzusetzen.';
    public const START_LABEL = 'Objekt starten';

    public function __construct(
        protected Factory $ui_factory,
        protected \LSUrlBuilder $url_builder,
        protected string $goto_command
    ) {
    }

    /**
     * The friendly statement shown above the boxes; usable as page title.
     */
    public function getHeadline(): string
    {
        return self::HEADLINE;
    }

    /**
     * Builds the choice page content: a short lead text followed by a deck of
     * one card per selectable successor.
     *
     * @param \LSLearnerItem[] $successors
     * @return Component[]
     */
    public function build(array $successors): array
    {
        $cards = [];
        foreach ($successors as $successor) {
            $cards[] = $this->buildCard($successor);
        }

        $deck = $this->ui_factory->deck($cards)->withNormalCardsSize();

        return [
            $this->ui_factory->legacy()->content(
                '<p class="lead">' . htmlspecialchars(self::SUBLINE) . '</p>'
            ),
            $deck
        ];
    }

    /**
     * Builds a single "box" (card) for one selectable object.
     */
    public function buildCard(\LSLearnerItem $item): Card
    {
        $sections = [];

        $description = trim($item->getDescription());
        if ($description !== '') {
            $sections[] = $this->ui_factory->legacy()->content(
                '<p>' . nl2br(htmlspecialchars($description)) . '</p>'
            );
        }

        $sections[] = $this->ui_factory->button()->standard(
            self::START_LABEL,
            $this->url_builder->getHref(
                $this->goto_command,
                \ilObject::_lookupObjId($item->getRefId())
            )
        );

        $card = $this->ui_factory->card()->standard($item->getTitle());

        $icon_path = $item->getIconPath();
        if ($icon_path !== '') {
            $image = $this->ui_factory->image()->standard($icon_path, $item->getTitle());
            $card = $card->withImage($image);
        }

        return $card->withSections($sections);
    }
}
