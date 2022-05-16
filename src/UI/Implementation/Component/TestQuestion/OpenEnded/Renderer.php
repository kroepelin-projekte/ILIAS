<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\OpenEnded;

use ILIAS\UI\Implementation\Render\AbstractComponentRenderer;
use ILIAS\UI\Renderer as RendererInterface;
use ILIAS\UI\Component;

class Renderer extends AbstractComponentRenderer
{
    
    public function render(Component\Component $component, RendererInterface $default_renderer)
    {
        // TODO: Implement render() method.
        $this->checkComponent($component);
    }
    
    protected function getComponentInterfaceName()
    {
        // TODO: Implement getComponentInterfaceName() method.
    }
}