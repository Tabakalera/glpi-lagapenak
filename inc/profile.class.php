<?php

/**
 * Injects a "Lagapenak" tab into GLPI's Profile form so that administrators
 * can assign read/create/update/delete rights per profile.
 */
class PluginLagapenakProfile extends CommonGLPI {

    static function getTypeName($nb = 0) {
        return 'Lagapenak';
    }

    static function getAllRights() {
        return [
            [
                'itemtype' => 'PluginLagapenakLoan',
                'label'    => PluginLagapenakLoan::getTypeName(2),
                'field'    => 'plugin_lagapenak_loan',
            ],
            [
                'itemtype' => 'PluginLagapenakLoan',
                'label'    => 'Albarán',
                'field'    => 'plugin_lagapenak_albaran',
            ],
        ];
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Profile) {
            return self::getTypeName(2);
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof Profile) {
            self::showForProfile($item);
        }
        return true;
    }

    static function showForProfile(Profile $profile) {
        global $CFG_GLPI;

        $ID      = $profile->getID();
        $canedit = $profile->canEdit($ID);

        echo "<form method='post' action='" . $CFG_GLPI['root_doc'] . "/front/profile.form.php'>";
        echo Html::hidden('id', ['value' => $ID]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(2),
        ]);

        if ($canedit) {
            echo '<div class="center mt-2">';
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo '</div>';
        }

        Html::closeForm();
        return true;
    }
}
