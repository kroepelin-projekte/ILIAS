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

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ILIAS\Data\Factory as DataFactory;
use ILIAS\DI\Container;
use ILIAS\LearningSequence\Player\LSChoicePageBuilder;
use ILIAS\UI\Component\Image\Image;
use ILIAS\UI\Component\Listing\Factory as ListingFactory;
use ILIAS\UI\Component\Symbol\Icon\Icon;
use ILIAS\UI\Implementation\Component as CImpl;

require_once(__DIR__ . '/../IliasMocks.php');

class LSChoicePageBuilderTest extends TestCase
{
    use IliasMocks;

    protected const GOTO_COMMAND = 'goto';
    public const OBJ_ID = 42;

    protected ?Container $dic_backup = null;
    protected ILIAS\UI\Factory $ui_factory;
    protected LSUrlBuilder $url_builder;

    protected function setUp(): void
    {
        global $DIC;
        $this->dic_backup = $DIC;

        $lng = $this->getMockBuilder(ilLanguage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['txt'])
            ->getMock();
        $lng->method('txt')->willReturnArgument(0);
        $DIC = new Container();
        $DIC['lng'] = $lng;

        $listing_factory = $this->createMock(ListingFactory::class);
        $listing_factory->method('property')->willReturnCallback(
            static fn() => new CImpl\Listing\Property()
        );

        /** @var ILIAS\UI\Factory&MockObject $ui_factory */
        $ui_factory = $this->mockUIFactory();
        $ui_factory->method('card')->willReturn(new CImpl\Card\Factory());
        $ui_factory->method('deck')->willReturnCallback(
            static fn(array $cards) => new CImpl\Deck\Deck($cards, CImpl\Deck\Deck::SIZE_S)
        );
        $ui_factory->method('listing')->willReturn($listing_factory);
        $this->ui_factory = $ui_factory;

        $this->url_builder = new LSUrlBuilder((new DataFactory())->uri('https://ilias.de/somepath'));
    }

    protected function tearDown(): void
    {
        global $DIC;
        $DIC = $this->dic_backup;
    }

    protected function getBuilder(): LSChoicePageBuilder
    {
        return new class ($this->ui_factory, $this->url_builder, self::GOTO_COMMAND) extends LSChoicePageBuilder {
            protected function preloadObjects(array $successors): void
            {
            }

            protected function lookupObjId(int $ref_id): int
            {
                return LSChoicePageBuilderTest::OBJ_ID;
            }

            protected function getTileImage(LSLearnerItem $item): Image
            {
                return (new CImpl\Image\Factory())->responsive('tile.jpg', $item->getTitle());
            }
        };
    }

    protected function getItem(string $title, string $description): LSLearnerItem
    {
        $ls_item = new LSItem(
            'crs',
            $title,
            $description,
            'icon_path',
            true,
            10,
            new ilLSPostCondition(30, 'always'),
            30,
            3
        );
        return new LSLearnerItem(6, 2, 3, $ls_item);
    }

    protected function getStartUrl(): string
    {
        return $this->url_builder->getHref(self::GOTO_COMMAND, self::OBJ_ID);
    }

    public function testBuildReturnsOnlyADeckOfRepositoryObjectCards(): void
    {
        $content = $this->getBuilder()->build([
            $this->getItem('first', 'description'),
            $this->getItem('second', '')
        ]);

        $this->assertCount(1, $content);
        $this->assertInstanceOf(CImpl\Deck\Deck::class, $content[0]);
        $this->assertCount(2, $content[0]->getCards());
        $this->assertContainsOnlyInstancesOf(ILIAS\UI\Component\Card\RepositoryObject::class, $content[0]->getCards());
    }

    public function testCardLinksTheTitleButNotTheImage(): void
    {
        $card = $this->getBuilder()->buildCard($this->getItem('first', ''));
        $this->assertInstanceOf(CImpl\Card\RepositoryObject::class, $card);

        $this->assertEquals('first', $card->getTitle());
        $this->assertEquals($this->getStartUrl(), $card->getTitleAction());
        $this->assertEquals('tile.jpg', $card->getImage()->getSource());
        $this->assertEmpty($card->getImage()->getAction());
    }

    public function testCardShowsTheObjectIcon(): void
    {
        $card = $this->getBuilder()->buildCard($this->getItem('first', ''));
        $this->assertInstanceOf(CImpl\Card\RepositoryObject::class, $card);

        $icon = $card->getObjectIcon();
        $this->assertInstanceOf(CImpl\Symbol\Icon\Custom::class, $icon);
        $this->assertEquals('icon_path', $icon->getIconPath());
        $this->assertEquals('obj_crs', $icon->getLabel());
        $this->assertEquals(Icon::MEDIUM, $icon->getSize());
    }

    public function testCardWithDescription(): void
    {
        $card = $this->getBuilder()->buildCard($this->getItem('first', ' description '));
        $this->assertInstanceOf(CImpl\Card\RepositoryObject::class, $card);

        $sections = $card->getSections();
        $this->assertCount(2, $sections);
        $this->assertInstanceOf(CImpl\Listing\Property::class, $sections[0]);
        $this->assertEquals([['description', 'description', false]], $sections[0]->getItems());
        $this->assertInstanceOf(ILIAS\UI\Component\Button\Standard::class, $sections[1]);
        $this->assertEquals(LSChoicePageBuilder::START_LABEL, $sections[1]->getLabel());
        $this->assertEquals($this->getStartUrl(), $sections[1]->getAction());
    }

    public function testCardWithoutDescription(): void
    {
        $card = $this->getBuilder()->buildCard($this->getItem('second', ''));
        $this->assertInstanceOf(CImpl\Card\RepositoryObject::class, $card);

        $sections = $card->getSections();
        $this->assertCount(1, $sections);
        $this->assertInstanceOf(ILIAS\UI\Component\Button\Standard::class, $sections[0]);
    }

    public function testCardEscapesTheTitle(): void
    {
        $card = $this->getBuilder()->buildCard($this->getItem('<b>first</b>', ''));
        $this->assertInstanceOf(CImpl\Card\RepositoryObject::class, $card);

        $this->assertEquals('&lt;b&gt;first&lt;/b&gt;', $card->getTitle());
    }
}
