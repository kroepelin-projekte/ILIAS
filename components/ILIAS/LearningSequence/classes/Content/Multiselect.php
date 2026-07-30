<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content;

use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Component\Input\Field\Node\Leaf;
use ILIAS\UI\Component\Input\Field\Node\NodeRetrieval;
use ILIAS\UI\Component\Input\Field\Node\Node;
use ILIAS\UI\Factory as UIFactory;
use ILIAS\UI\Component\Input\Field\Node\Factory as NodeFactory;
use ILIAS\UI\Component\Symbol\Icon\Factory as IconFactory;

class Multiselect
{
    public function __construct(
        protected UIFactory $ui_factory,
        protected \ilLanguage $lng
    ) {
    }

    public function getPicker(string $label, bool $multi, array $lso_items): FormInput
    {
        $retrieval = new class ($lso_items) implements NodeRetrieval {
            protected array $items;

            public function __construct(array $items)
            {
                $this->items = $items;
            }

            public function getNodes(
                NodeFactory $node_factory,
                IconFactory $icon_factory,
                array $sync_node_id_whitelist = [],
                ?string $parent_id = null,
            ): \Generator {
                if ($parent_id !== null) {
                    return;
                }

                foreach ($this->items as $item) {
                    $ref_id = (string) $item->getRefId();
                    $obj_id = \ilObject::_lookupObjId((int) $ref_id);
                    $title = \ilObject::_lookupTitle($obj_id);
                    $type = \ilObject::_lookupType($obj_id);
                    $icon = $icon_factory->standard($type, $title);
                    yield $node_factory->leaf(
                        [$ref_id],
                        $title,
                        $icon
                    );
                }
            }

            public function getNodesAsLeaf(
                NodeFactory $node_factory,
                IconFactory $icon_factory,
                array $node_ids,
            ): \Generator {
                foreach ($this->items as $item) {
                    $ref_id = (string) $item->getRefId();
                    if (in_array($ref_id, $node_ids)) {
                        $obj_id = \ilObject::_lookupObjId((int) $ref_id);
                        $title = \ilObject::_lookupTitle($obj_id);
                        $type = \ilObject::_lookupType($obj_id);
                        $icon = $icon_factory->standard($type, $title);
                        yield $node_factory->leaf([$ref_id], $title, $icon);
                    }
                }
            }
        };

        if ($multi) {
            return $this->ui_factory->input()->field()->treeMultiSelect($retrieval, $label)
                ->withSelectChildNodes(false);
        }

        return $this->ui_factory->input()->field()->treeSelect($retrieval, $label);
    }
}
