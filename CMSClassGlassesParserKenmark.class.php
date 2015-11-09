<?php

class CMSClassGlassesParserKenmark extends CMSClassGlassesParser {

    const URL_BASE = 'http://kenmarkoptical.com';
    const URL_MAIN = 'http://customer.kenmarkoptical.com';
    const URL_LOGIN = 'http://customer.kenmarkoptical.com/User/Login';
    const URL_BRANDS = 'http://customer.kenmarkoptical.com/Home/Main#a';
    const URL_HOME = 'http://customer.kenmarkoptical.com/Home/Main';
    const URL_CATEGORY = "http://customer.kenmarkoptical.com/Collections/GetFrames?coll=%s&group=%s&page=%s&height=504&width=1903&count=0&sort=1";
    const URL_PRODUCT = 'http://customer.kenmarkoptical.com/FrameDetail/Index?sku=';
    const URL_IMAGE = 'http://kenmark.kenmarkoptical.com/showimage.aspx?img=%s.jpg&w=1150';
    const URL_BRAND = 'http://customer.kenmarkoptical.com/Collections/Index/%s';

    /**
     * @return CMSLogicProvider id for current provider
     */
    public function getProviderId() {
        return CMSLogicProvider::KENMARK;
    }

    /**
     * Login to account on site
     */
    public function doLogin() {
        $http = $this->getHttp();

        $http->doGet(self::URL_MAIN);

        $post = array (
            'Username' => $this->getUsername(),
            'Password' => $this->getPassword(),
            'ReturnUrl' => '',
            'HidePrices' => 'false'
        );

        $http->doPost(self::URL_LOGIN, $post);

        return $http->doGet(self::URL_HOME);
    }

    /**
     * Check login done
     * @return boolean           [true if login done]
     */
    public function isLoggedIn($contents) {
        return strpos($contents, 'Log Out') !== false;
    }

    /**
     * Синхронизация брендов
     */
    public function doSyncBrands() {
        $http = $this->getHttp();

        if(!$http->doGet(self::URL_BRANDS)) {
            throw new CMSException();
        }

        $content = $http->getContents();

        $dom = str_get_html($content);

        $brands_dom = $dom->find('div.header ul');
        $brands_dom = $brands_dom[1]->find('li a');

        if(!$brands_dom) {
            throw new CMSException();
        }

        foreach($brands_dom as $brand) {
            $brand_code_exp = explode("/", $brand->attr['href']);
            $brand_code = strtoupper($brand_code_exp[3]);

            $brand_name = trim($brand->plaintext);

            $brand_url = self::URL_BASE . $brand->attr['href'];

            $brands[$brand_code] = array(
                'name' => $brand_name,
                'url' => $brand_url,
            );

        }
        if (!$brands) {
            throw new CMSException();
        }

        $myBrands = CMSLogicBrand::getInstance()->getAll($this->getProvider());
        $coded = array();

        foreach($myBrands as $b) {
            if($b instanceof CMSTableBrand) {
                $coded[$b->getCode()] = $b;
            }
        }

        foreach($brands as $code => $info) {
            if(!isset($coded[$code])) {
                echo 1 . "\n"; continue;
                CMSLogicBrand::getInstance()->create($this->getProvider(), $info['name'], $code, '');
            } else {
                echo "Brand {$info['name']} already isset.\n";
            }
        }
    }

    /**
     * Sync items on category page (get urls)
     */
    public function doSyncItems() {
        $http = $this->getHttp();
        $brands = CMSLogicBrand::getInstance()->getAll($this->getProvider());

        foreach($brands as $b){
            if($b instanceof CMSTableBrand) {
                if($b->getValid()) {
                    echo get_class($this), ': syncing items of brand: [', $b->getId(), '] ', $b->getTitle(), "\n";
                } else {
                    echo get_class($this), ': SKIP! syncing items of Disabled brand: [', $b->getId(), '] ', $b->getTitle(), "\n";
                    continue;
                }

                // Сбрасываем is_valid для моделей бренда - флаг наличия модели у провайдера
                $this->resetModelByBrand($b);
                // Сбрасываем сток для моделей
                $this->resetStockByBrand($b);

                $link_products = array();

                $brand_url = sprintf(self::URL_BRAND, $b->getCode());

                if(!$http->doGet($brand_url)) {
                    echo 'Get url fail: ', $brand_url, "\n";
                    continue;
                }

                $content = $http->getContents(false);

                preg_match("/var _coll = '([a-zA-Z]*)'/", $content, $matches);
                $b_code = $matches[1];

                $cat_arr = $this->getBrandCategories($content);

                foreach($cat_arr as $key => $colection) {
                    $link_products = array_merge($link_products, $this->getCatProducts($colection, $b_code));
                }

                echo '--Parse items links found: ', sizeof($link_products), "\n";

                if(empty($link_products)) {
                    echo "----No one products.\n";
                    continue;
                }

                foreach($link_products as $l) {
                    $this->parsePageItems($l['href'],$l['type'], $b);
                }
            }
        }
    }

    /**
     * Возвращает категории бренда
     * @param  [type] $brand [description]
     * @return array
     */
    private function getBrandCategories($content) {
        $cat_arr = array();

        $dom = str_get_html($content);
        $links = $dom->find('span.collectionLink');

        foreach($links as $a) {
            $url_text = $a->innertext();

            // не понятная категория
            if(stripos("pop", $url_text) !== false) continue;

            $cat_arr[] = array(
                'href' => $url_text
            );
        }

        return $cat_arr;
    }

    /**
     * Возвращает товары одной категории
     * @param  object $colection [description]
     * @param  string $b_code
     * @return array
     */
    private function getCatProducts($colection, $b_code) {
        $http = $this->getHttp();
        $link_products = array();
        $page = 0;

        while(true) {
            if(stripos($colection['href'], "SUN") !== false){
                $type = CMSLogicGlassesItemType::getInstance()->getSun();
            } else {
                $type = CMSLogicGlassesItemType::getInstance()->getEye();
            }

            $group = str_replace(" ", "+", $colection['href']);

            $category_url = sprintf(self::URL_CATEGORY, $b_code, $group, $page);
            $http->doGet($category_url);

            $content = $http->getContents();
            $dom = str_get_html($content);

            $links = $dom->find('body');

            $links = $links[0]->innertext();

            if(stripos($links, "No Results Found") !== false) {
                break;
            }

            $links = explode('class=\"grid', $links);

            if(count($links) < 2) {
                break;
            }

            foreach($links as $key => $a) {
                // первый пропускаем
                if(!$key) continue;

                preg_match('/img=([a-zA-Z]*[0-9]*[a-zA-Z]*)/', $a, $matches);
                $img_name = $matches[1];

                $link_products[] = array(
                    'href' => self::URL_PRODUCT . $img_name,
                    'type' => $type,
                );
            }

            $page++;
        }

        return $link_products;
    }

    /**
     * Парсим модель по ее ссылке
     * @param  string        $url  [ссылка]
     * @param  string        $type  [тип (frames or sun)]
     * @param  CMSTableBrand $brand [бренд]
     */
    private function parsePageItems($url, $type, CMSTableBrand $brand) {
        $http = $this->getHttp();
        echo "----{$url}\n";

        if(!$http->doGet($url)) {
            echo "GET URL FAIL! {$url}\n";
            return;
        }

        // получаем html старницу модели
        $content = $http->getContents();
        $dom = str_get_html($content);

        // достаем название
        $title = $dom->find('div.group7 span');
        $title = trim($title[0]->innertext());

        if(stripos($title, "Side Shield") !== false) {
            echo "-----Not valid. Side Shield.!\n";
            return;
        }

        echo "-----parsing {$title}\n";

        // достаем цену
        $price_dom = $dom->find('div.MiniFrameDIV');
        preg_match('/Wholesale:.*?\$(.*?\.\d{2})/', $price_dom[0]->plaintext, $matches);
        $price = isset($matches[1]) ? $matches[1] : 0;

        if(!$price) {
            echo "-----Price fail (0$).!\n";
            return;
        }

        $table_dom = $dom->find('#FrameDetailContainer tr');

        // удаляем первый и последний элементы
        // так как это шапка и подвал таблицы
        unset($table_dom[count($table_dom) - 1]);
        unset($table_dom[0]);

        $i = 0;
        foreach($table_dom as $key => $tr) {
            $description_selector = "#OrderFrames_{$i}__Description";
            $upc_selector = "#OrderFrames_{$i}__UPC";
            $sku_selector = "#OrderFrames_{$i}__SKU";

            $description_dom = $tr->find($description_selector);
            $description = trim($description_dom[0]->value);
            $description = trim(str_replace($title, '', $description));

            // достаем название цвета и размеры
            preg_match('/(.*)[ ]+(.*)/', $description, $matches);
            $color = trim($matches[1]);
            $sizes = trim($matches[2]);

            $sizes_arr = explode('-', $sizes);
            $size_1 = isset($sizes_arr[0]) ? $sizes_arr[0] : 0;
            $size_2 = isset($sizes_arr[1]) ? $sizes_arr[1] : 0;
            $size_3 = isset($sizes_arr[2]) ? $sizes_arr[2] : 0;

            // берем upc
            $upc_dom = $tr->find($upc_selector);
            $upc = trim($upc_dom[0]->value);

            // код цвета для дальнейшего получения изображения
            $sku_dom = $tr->find($sku_selector);
            $sku = trim($sku_dom[0]->value);

            $img_key = rawurlencode(str_replace($size_1, '', $sku));
            $image = sprintf(self::URL_IMAGE, $img_key);

            $stock = stripos($tr, "Out Of Stock") !== false ? 0 : 1;

            // небольшой лог
            echo "\n------url          - " . $url."\n";
            echo "------item title   - " . $title. "\n";
            echo "------item ext id  - " . $title. "\n";
            echo "------color title  - " . $color. "\n";
            echo "------color code   - " . "~". "\n";
            // echo "type         - " . $type. "\n";
            echo "------item sizes   - " . $sizes. "\n";
            echo "------item image   - " . $image. "\n";
            echo "------price        - " . $price . "\n";
            echo "------upc          - " . $upc . "\n";
            echo "------stock        - " . $stock. "\n";
            echo "-------------------------------------------------------------------------------\n";

            if(strlen($upc) < 13) {
                $upc = "0" . $upc;
            }

            // создаем обьект модели и синхронизируем
            $item = new CMSClassGlassesParserItem();
            $item->setBrand($brand);
            $item->setTitle($title);
            $item->setExternalId($title);
            $item->setType($type);
            $item->setColor($color);
            $item->setColorCode("");
            $item->setStockCount($stock);
            $item->setPrice($price);
            $item->setImg($image);
            $item->setSize($size_1);
            $item->setSize2($size_2);
            $item->setSize3($size_3);
            $item->setIsValid(1);
            $item->setUpc($upc);

            $result[] = $item;

            $i++;
        }

        echo "\n===============================================================================\n\n";
        $dom->clear();

        // синхронизируем вариации одной модели
        foreach($result as $res) {
            $res->sync();
        }
    }
}