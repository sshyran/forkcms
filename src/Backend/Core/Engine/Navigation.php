<?php

namespace Backend\Core\Engine;

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

use Symfony\Component\HttpKernel\KernelInterface;
use Backend\Core\Engine\Model as BackendModel;
use Backend\Modules\Pages\Engine\Model as BackendPagesModel;
use Backend\Core\Language\Language as BackendLanguage;

final class Navigation extends Base\Object
{
    /** @var array */
    private $navigation;

    /**
     * @param KernelInterface $kernel
     */
    public function __construct(KernelInterface $kernel)
    {
        parent::__construct($kernel);

        // store for later use throughout the application
        $this->getContainer()->set('navigation', $this);

        $this->buildEditorLinkListIfNeeded();
        $this->navigation = $this->getNavigationForAllowedModulesAndActions();
    }

    /**
     * @param TwigTemplate $template
     */
    public function parse(TwigTemplate $template)
    {
        $template->assign('navigation', $this->navigation);
    }

    /**
     * @param array $navigation
     *
     * @return array
     */
    private function addActiveStateToNavigation(array $navigation): array
    {
        $selectedKey = $this->getSelectedKey($navigation);

        if ($selectedKey === null) {
            return $navigation;
        }

        return array_map(
            function ($key, $navigationItem) use ($selectedKey) {
                if ($key !== $selectedKey) {
                    return $navigationItem;
                }

                $navigationItem['active'] = true;

                if (!empty($navigationItem['children'])) {
                    $navigationItem['children'] = $this->addActiveStateToNavigation($navigationItem['children']);
                }

                return $navigationItem;
            },
            array_keys($navigation),
            $navigation
        );
    }

    /**
     * @param array $navigationItem
     *
     * @return array
     */
    private function getNavigationItemForCurrentlyAuthenticatedUser(array $navigationItem): array
    {
        if (!isset($navigationItem['url'], $navigationItem['label'])
            || empty($navigationItem['url'])
            || empty($navigationItem['label'])) {
            return [];
        }

        list($module, $action) = explode('/', $navigationItem['url']);
        $module = \SpoonFilter::toCamelCase($module);
        $action = \SpoonFilter::toCamelCase($action);

        if (Authentication::isAllowedModule($module) && Authentication::isAllowedAction($action, $module)) {
            return $navigationItem;
        }

        return [];
    }

    /**
     * @return array
     */
    private function getNavigationForAllowedModulesAndActions(): array
    {
        $navigation = $this->getContainer()->get('cache.backend_navigation')->get();

        if (Authentication::getUser()->isGod()) {
            return $this->addActiveStateToNavigation($navigation);
        }

        return $this->addActiveStateToNavigation(
            array_filter(array_map($this->getPermissionCheckerFunction(), $navigation))
        );
    }

    /**
     * @return callable
     */
    private function getPermissionCheckerFunction(): callable
    {
        return function (array $navigationItem) {
            if (!isset($navigationItem['children'])
                || !is_array($navigationItem['children'])
                || empty($navigationItem['children'])
            ) {
                return $this->getNavigationItemForCurrentlyAuthenticatedUser($navigationItem);
            }

            $navigationItem['children'] = array_filter(
                array_map($this->getPermissionCheckerFunction(), $navigationItem['children'])
            );

            if (empty($navigationItem['children'])) {
                unset($navigationItem['children']);

                return $this->getNavigationItemForCurrentlyAuthenticatedUser($navigationItem);
            }

            // reset the base url to the first allowed url
            $navigationItem['url'] = reset($navigationItem['children'])['url'];

            return $this->getNavigationItemForCurrentlyAuthenticatedUser($navigationItem);
        };
    }

    /**
     * Try to determine the selected state
     *
     * @param array $navigationItem
     * @param string $activeURL
     *
     * @return bool
     */
    private function navigationItemMatchesActiveUrl(array $navigationItem, string $activeURL): bool
    {
        if ($navigationItem['url'] === $activeURL
            || (isset($navigationItem['selected_for']) && in_array($activeURL, $navigationItem['selected_for'], true))
        ) {
            return true;
        }

        if (!isset($navigationItem['children'])) {
            return false;
        }

        foreach ($navigationItem['children'] as $childNavigationItem) {
            if ($this->navigationItemMatchesActiveUrl($childNavigationItem, $activeURL)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the selected key based on the current module/actions
     *
     * @param array $navigation
     *
     * @return int|null
     */
    private function getSelectedKey(array $navigation)
    {
        $url = $this->get('url');
        $activeUrl = BackendModel::camelCaseToLowerSnakeCase($url->getModule() . '/' . $url->getAction());
        foreach ($navigation as $key => $navigationItem) {
            if ($this->navigationItemMatchesActiveUrl($navigationItem, $activeUrl)) {
                return $key;
            }
        }
    }

    private function buildEditorLinkListIfNeeded()
    {
        $editorLinkListCache = sprintf(
            '%1$s/Navigation/editor_link_list_%2$s.js',
            FRONTEND_CACHE_PATH,
            BackendLanguage::getWorkingLanguage()
        );

        if (!is_file($editorLinkListCache)) {
            BackendPagesModel::buildCache(BackendLanguage::getWorkingLanguage());
        }
    }
}
