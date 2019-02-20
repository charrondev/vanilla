<?php
/**
 * @copyright 2009-2019 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

use Garden\Schema\Schema;
use Garden\Web\Data;
use Garden\Web\Exception\NotFoundException;
use Vanilla\ApiUtils;
use Vanilla\AddonManager;
use Vanilla\Addon;
use Vanilla\Models\ThemeModel;
use Vanilla\Models\ThemeAssetModel;

/**
 * API Controller for the `/drafts` resource.
 */
class ThemesApiController extends AbstractApiController {
    const ASSET_TYPES = ['html', 'js', 'json', 'css'];

    const ASSET_LIST = [
        "header" => [
            "type" => "html",
            "file" => "header.html"
        ],
        "footer" => [
            "type" => "html",
            "file" => "footer.html"
        ],
        "variables" => [
            "type" => "json",
            "file" => "variables.json"
        ],
        "fonts" => [
            "type" => "json",
            "file" => "fonts.json"
        ],
        "scripts" => [
            "type" => "json",
            "file" => "scripts.json"
        ],
        "styles" => [
            "type" => "css",
            "file" => "styles.css"
        ],
        "inlineJs" => [
            "type" => "js",
            "file" => "inlineJs.js"
        ]

    ];

    /* @var AddonManager */
    private $addonManager;

    /* @var ThemeModel */
    private $themeModel;

    /* @var ThemeAssetModel */
    private $themeAssetModel;

    /**
     * @inheritdoc
     */
    public function __construct(
        AddonManager $addonManager,
        ThemeModel $themeModel,
        ThemeAssetModel $themeAssetModel
    ) {
        $this->addonManager = $addonManager;
        $this->themeModel = $themeModel;
        $this->themeAssetModel = $themeAssetModel;
    }

    /**
     * Get a theme assets.
     *
     * @param string $themeKey The unique theme key or theme ID.
     * @return array
     */
    public function get(string $themeKey): array {

        $in = $this->themeKeySchema('in')->setDescription('Get theme assets.');
        $out = $this->themeResultSchema('out');

        $themeAssets = $this->getThemeAssets($themeKey);
        $themeAssets = $out->validate($themeAssets);
        return $themeAssets;
    }

    /**
     * Create new theme from parent theme.
     *
     * @param array $body Array of incoming params.
     * @return array
     */
    public function post(array $body): array {
        $this->permission("Garden.Settings.Manage");

        $in = $this->themePostSchema('in')->setDescription('Post theme assets.');
        $out = $this->themeResultSchema('out');
        $body = $in->validate($body);
        $themeID = $this->themeModel->insert($body);
        if (isset($body['assets'])) {
            foreach ($body['assets'] as $assetKey => $assetProps) {
                if ($assetProps['type'] === 'html') {
                    $assetRecord = [
                        'themeID' => $themeID,
                        'assetKey' =>$assetKey,
                        'data' => $assetProps['body']
                    ];
                    $this->themeAssetModel->insert($assetRecord);
                }
            }
        }

        $theme = $this->getThemeAssets($themeID);
        $themeAssets = $out->validate($theme);
        return $themeAssets;
    }

    /**
     * Get theme asset.
     *
     * @param string $id The unique theme key or theme ID (ex: keystone).
     * @param string $assetKey Unique asset key (ex: header, footer, fonts, styles)
     *        Note: assetKey can be filename (ex: header.html, styles.css)
     *              in that case file content returned instaed of json structure
     * @link https://github.com/vanilla/roadmap/blob/master/theming/theming-data.md#api
     *
     * @return array|Data
     */
    public function get_assets(string $id, string $assetKey) {

        $in = $this->themeKeySchema('in')->setDescription('Get theme assets.');
        $out = $this->schema([], 'out');

        $pathInfo =  pathinfo($assetKey);
        $asset =  $this->getThemeAsset($id, $pathInfo['filename'], !empty($pathInfo['extension']));
        if (empty($pathInfo['extension'])) {
            // return asset as an array
            return $asset;
        } else {
            // return asset as a file
            return new Data($asset['data'], ['CONTENT_TYPE' => $asset['mime-type']]);
        }
    }

    /**
     * Get ThemeKey schema
     *
     * @param string $type
     * @return Schema
     */
    public function themeKeySchema(string $type = 'in'): Schema {
        static $schema;
        if (!isset($schema)) {
            $schema = $this->schema(
                Schema::parse(['themeKey:s' => [
                    'description' => 'Theme name.',
                    'enum' => $this->getAllThemes()
                ]]),
                $type
            );
        }
        return $this->schema($schema, $type);
    }

    /**
     * POST theme schema
     *
     * @param string $type
     * @return Schema
     */
    public function themePostSchema(string $type = 'in'): Schema {
        $schema = $this->schema(
            Schema::parse([
                'parentTheme:s' => [
                    'description' => 'Parent theme name.',
                    'enum' => $this->getAllThemes()
                ],
                'parentVersion:s' => 'Parent theme version.',
                'assets?' => $this->assetsSchema()
            ]),
            $type
        );
        return $schema;
    }

    /**
     * Result theme schema
     *
     * @param string $type
     * @return Schema
     */
    public function themeResultSchema(string $type = 'out'): Schema {
        $schema = $this->schema(
            Schema::parse([
                'type:s',
                'themeID:s',
                'ver:s',
                'parentTheme:s' => [
                    'description' => 'Parent theme name.',
                    'enum' => $this->getAllThemes()
                ],
                'parentVersion:s' => 'Parent theme version.',
                'logos:s?',
                'mobileLogo:s?',
                'assets?' => $this->assetsSchema()
            ]),
            $type
        );
        return $schema;
    }

    /**
     * Get 'assets' schema
     *
     * @return Schema
     */
    public function assetsSchema(): Schema {
        $schema = Schema::parse([
            "header?" => Schema::parse([
                "type:s" => ['description' => 'Header asset type: html.', 'enum' => ['html']],
                "body:s"
            ]),
            "footer?" => Schema::parse([
                "type:s" => ['description' => 'Footer asset type: html.', 'enum' => ['html']],
                "body:s"
            ]),
            "variables?" => Schema::parse([
                "type:s" => ['description' => 'Variables asset type: json.', 'enum' => ['json']],
                "data:o"
            ]),
            "fonts?" => Schema::parse([
                "type:s" => ['description' => 'Fonts asset type: data.', 'enum' => ['data']],
                'data:a' => Schema::parse([
                    "type:s",
                    "name:s",
                    "fallbacks:s?",
                    "url:s"
                ])
            ]),
            "scripts?" => Schema::parse([
                "type:s" => ['description' => 'Scripts asset type: data.', 'enum' => ['data']],
                "data:a"
            ]),
            "styles?" => Schema::parse([
                "type:s" => ['description' => 'Styles asset type: css.', 'enum' => ['css']],
                "data:s"
            ]),
            "inlineJs?" => Schema::parse([
                "type:s"=> ['description' => 'Javascript asset type: js.', 'enum' => ['js']],
                "js:s"
            ])
        ]);
        return $schema;
    }

    /**
     * Get custom theme by ID
     *
     * @param int $themeID
     * @return array
     */
    public function getThemeByID(int $themeID) {
        $theme = $this->themeModel->get(['themeID' => $themeID]);
        if (empty($theme)) {
            throw new NotFoundException('Theme '.$themeID.' not found');
        }
        return $theme[0];
    }

    /**
     * Get theme by name
     *
     * @param string $themeName
     * @return Addon Returns theme addon
     *
     * @throws NotFoundException Throws an exception when themeName not found.
     */
    public function getThemeByName(string $themeName): Addon {
        $theme = $this->addonManager->lookupTheme($themeName);
        if (null === $theme) {
            throw new NotFoundException('There is no theme: \''.$themeName.'\' installed.');
        }
        return $theme;
    }

    /**
     * Get list of all available themes
     *
     * @return array List of all available themes
     */
    public function getAllThemes(): array {
        $themes = $this->addonManager->lookupAllByType(Addon::TYPE_THEME);
        $result = [];
        foreach ($themes as $theme) {
            $result[] = $theme->getKey();
        }
        return $result;
    }

    /**
     * Get all theme assets
     *
     * @param string $themeKey
     * @return array
     */
    public function getThemeAssets(string $themeKey): array {
        $customAssets = [];
        if (ctype_digit($themeKey)) {
            $customAssets = $this->getThemeByID((int)$themeKey);
            $theme = $this->getThemeByName($customAssets['parentTheme']);
        } else {
            $theme = $this->getThemeByName($themeKey);
        }

        $assets  = $theme->getInfoValue('assets');
        $res = [];
        $res['type'] = 'themeFile';
        $res['themeID'] = $customAssets['themeID'] ?? $theme->getInfoValue('key');
        $res['parentTheme'] = $customAssets['parentTheme'] ?? $theme->getInfoValue('key');
        $res['ver'] = $theme->getInfoValue('version');
        $res['parentVersion'] = $customAssets['parentVersion'] ?? $theme->getInfoValue('version');
        $res['logos'] = $theme->getInfoValue('logos');
        $res['mobileLogo'] = $theme->getInfoValue('mobileLogo');
        foreach ($assets as $aKey => &$aVal) {
            $fileName = PATH_ROOT.$theme->getSubdir().'/assets/'.$aVal['file'];
            $aVal['data'] = $customAssets[$aKey]['data'] ?? file_get_contents($fileName);
            switch ($aVal['type']) {
                case 'json':
                    $aVal['data'] = json_decode($aVal['data'], true);
                    break;
                case 'data':
                    $aVal['data'] = json_decode($aVal['data'], true);
                    break;
                case 'html':
                    $aVal['body'] = $aVal['data'];
                    break;
                case 'js':
                    $aVal['js'] = $aVal['data'];
                    break;
            }
        }
        $res['assets'] = $assets;
        return $res;
    }

    /**
     * Get theme asset by assetKey.
     *
     * @param string $id
     * @param string $assetKey
     * @param bool $mimeType
     * @return mixed
     * @throws NotFoundException Throws an exception if asset not found.
     */
    public function getThemeAsset(string $id, string $assetKey, bool $mimeType = false): array {
        $customAssets = [];
        if (ctype_digit($id)) {
            $customAssets[$assetKey] = $this->getThemeByID((int)$id, $assetKey);
            $theme = $this->getThemeByName($customAssets['parentTheme']);
        } else {
            $theme = $this->getThemeByName($id);
        }
        $assets  = $theme->getInfoValue('assets');
        if (key_exists($assetKey, $assets)) {
            $asset = $assets[$assetKey];
            $fileName = PATH_ROOT.$theme->getSubdir().'/assets/'.$asset['file'];
            $asset['data'] = $customAssets[$assetKey]['data'] ?? file_get_contents($fileName);
            if ($mimeType) {
                switch ($asset['type']) {
                    case 'json':
                        $asset['mime-type'] = 'application/json';
                        break;
                    case 'js':
                        $asset['mime-type'] = 'application/javascript';
                        break;
                    case 'css':
                        $asset['mime-type'] = 'text/css';
                        break;
                    case 'html':
                    default:
                        $asset['mime-type'] = 'text/html';
                }
            }
        } else {
            throw new NotFoundException('Asset "'.$assetKey.'" not found for "'.$theme->getInfoValue('key').'"');
        }
        return $asset;
    }
}
