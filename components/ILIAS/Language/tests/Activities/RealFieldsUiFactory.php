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

namespace ILIAS\Language\Tests\Activities;

use ILIAS\Language\Language;
use ILIAS\Data\Factory as DataFactory;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Implementation\Component\Input\Field\Factory as FieldFactoryImpl;
use ILIAS\UI\Implementation\Component\Input\Field\Node\Factory as NodeFactory;
use ILIAS\UI\Implementation\Component\Input\UploadLimitResolver;
use ILIAS\UI\Implementation\Component\SignalGenerator;

/**
 * Reusable helper mixed into every Activities test case that needs a
 * `\ILIAS\UI\Factory` whose `->input()->field()->text()/checkbox()/select()/
 * group()` return REAL, functioning UI framework Field objects - i.e. one
 * that GrindsFormInput::grind() (see the trait's own docblock) can actually
 * run `withNameFrom()`/`withInput()`/`getContent()` against, instead of a
 * plain PHPUnit mock, which would either return null or a broken mock object
 * for these calls (and grind()'s own try/catch would silently turn every
 * such call into a Result\Error).
 *
 * Only `\ILIAS\UI\Factory::input()` and `\ILIAS\UI\Component\Input\Factory::field()`
 * are ever called by these tests, so those two collaborators are plain
 * PHPUnit mocks stubbed to return the real, concrete field factory below -
 * see CommonFieldRendering::getFieldFactory() in
 * components/ILIAS/UI/tests/Component/Input/Field/CommonFieldRendering.php
 * for the same, established recipe.
 */
trait RealFieldsUiFactory
{
    /**
     * Builds a `\ILIAS\UI\Factory` whose `input()->field()` is a REAL
     * `\ILIAS\UI\Implementation\Component\Input\Field\Factory`, wired against
     * a real `\ILIAS\Data\Factory`/`\ILIAS\Refinery\Factory` pair and a
     * `\ILIAS\Language\Language` mock whose `txt()` simply echoes back the
     * key it was given.
     */
    protected function createRealFieldsUiFactory(): \ILIAS\UI\Factory
    {
        $data_factory = new DataFactory();
        $lng = $this->createMock(Language::class);
        $lng->method('txt')->willReturnArgument(0);
        $refinery = new RefineryFactory($data_factory, $lng);

        $field_factory = new FieldFactoryImpl(
            $this->createMock(NodeFactory::class),
            $this->createMock(UploadLimitResolver::class),
            new SignalGenerator(),
            $data_factory,
            $refinery,
            $lng
        );

        $input = $this->createMock(\ILIAS\UI\Component\Input\Factory::class);
        $input->method('field')->willReturn($field_factory);

        $ui = $this->createMock(\ILIAS\UI\Factory::class);
        $ui->method('input')->willReturn($input);

        return $ui;
    }
}
