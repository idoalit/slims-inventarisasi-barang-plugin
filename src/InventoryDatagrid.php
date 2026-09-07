<?php

namespace SLiMS\Plugins\Inventory;

defined('INDEX_AUTH') || die('Direct access not allowed!');

class InventoryDatagrid extends \simbio_datagrid
{
    public string $inventoryCsrf = '';
    public string $inventoryAction = '';

    protected function makeOutput($int_num2show = 30)
    {
        $html = parent::makeOutput($int_num2show);
        if (!$this->editable) {
            return $html;
        }

        // Keep the native SLiMS form and submit handler, with plugin CSRF protection.
        $hidden = '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($this->inventoryCsrf, ENT_QUOTES, 'UTF-8') . '" />'
            . '<input type="hidden" name="form_action" value="'
            . htmlspecialchars($this->inventoryAction, ENT_QUOTES, 'UTF-8') . '" />';

        return str_replace('</form>', $hidden . '</form>', $html);
    }
}
