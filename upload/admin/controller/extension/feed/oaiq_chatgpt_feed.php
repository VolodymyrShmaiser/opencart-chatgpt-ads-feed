<?php
class ControllerExtensionFeedOaiqChatgptFeed extends Controller {
	const P = 'feed_oaiq_chatgpt_feed_';
	private $error = array();

	private $fields = array(
		'status' => 0, 'key' => '', 'format' => 'csv', 'cache_hours' => 24, 'seller_name' => '', 'default_brand' => '',
		'store_country' => 'US', 'target_countries' => 'US', 'currency' => '', 'with_tax' => 1, 'id_source' => 'product_id',
		'image_size' => 0, 'shipping_price' => '', 'return_policy' => '', 'return_days' => '', 'privacy_url' => '', 'tos_url' => '',
		'ads_eligible' => 1, 'categories' => array(), 'exclude_ids' => '', 'stock_map' => array(),
		'variant_options' => array(), 'delta_status' => 0, 'delta_api_key' => '', 'delta_feed_id' => '', 'delta_key' => '',
	);

	public function index() {
		$this->load->language('extension/feed/oaiq_chatgpt_feed');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
			$post = $this->sanitize($this->request->post);
			$this->model_setting_setting->editSetting('feed_oaiq_chatgpt_feed', $post);
			$this->registerEvents();
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('extension/feed/oaiq_chatgpt_feed', 'user_token=' . $this->session->data['user_token'], true));
		}

		$data = array();
		foreach (array('heading_title', 'text_edit', 'text_enabled', 'text_disabled', 'button_save', 'button_cancel') as $k) {
			$data[$k] = $this->language->get($k);
		}
		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['success'] = '';
		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		}

		$token = 'user_token=' . $this->session->data['user_token'];
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token, true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=feed', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/feed/oaiq_chatgpt_feed', $token, true)),
		);
		$data['action'] = $this->url->link('extension/feed/oaiq_chatgpt_feed', $token, true);
		$data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=feed', true);

		foreach ($this->fields as $name => $default) {
			$key = self::P . $name;
			if (isset($this->request->post[$key])) {
				$data[$name] = $this->request->post[$key];
			} elseif ($this->config->get($key) !== null) {
				$data[$name] = $this->config->get($key);
			} else {
				$data[$name] = $default;
			}
		}
		if (!$data['key']) {
			$data['key'] = bin2hex(random_bytes(16));
		}
		// Never send the stored API key back to the browser.
		$data['delta_api_key_set'] = (bool)$this->config->get(self::P . 'delta_api_key');
		$data['delta_api_key'] = '';
		if (!$data['seller_name']) {
			$data['seller_name'] = $this->config->get('config_name');
		}

		$catalog = rtrim(HTTPS_CATALOG ? HTTPS_CATALOG : HTTP_CATALOG, '/') . '/';
		$fmt = in_array($data['format'], array('csv', 'tsv', 'jsonl'), true) ? $data['format'] : 'csv';
		$data['feed_url'] = $catalog . 'chatgpt-feed/' . $data['key'] . '.' . $fmt;
		$data['catalog_url'] = $catalog;
		$data['feed_published'] = false;
		$data['publish_error'] = '';
		$stats_file = DIR_STORAGE . 'oaiq_chatgpt_feed/stats_0.json';
		$st = is_file($stats_file) ? json_decode(file_get_contents($stats_file), true) : null;
		if (!empty($st['published']) && is_file(dirname(DIR_APPLICATION) . '/' . $st['published'])) {
			$data['feed_url'] = $catalog . $st['published'];
			$data['feed_published'] = true;
		} elseif (!empty($st['publish_error'])) {
			$data['publish_error'] = $st['publish_error'];
		}
		$data['cron_url'] = $catalog . 'index.php?route=extension/feed/oaiq_chatgpt_feed/generate&key=' . $data['key'];

		$this->load->model('catalog/category');
		$data['all_categories'] = $this->model_catalog_category->getCategories(array('sort' => 'name'));

		$this->load->model('catalog/option');
		$data['all_options'] = $this->model_catalog_option->getOptions(array('sort' => 'od.name'));
		$data['delta_url'] = $catalog . 'index.php?route=extension/feed/oaiq_chatgpt_feed/delta&dkey=' . $this->config->get(self::P . 'delta_key') . '&product_id=';

		$this->load->model('localisation/currency');
		$data['currencies'] = $this->model_localisation_currency->getCurrencies();
		$this->load->model('localisation/stock_status');
		$data['stock_statuses'] = $this->model_localisation_stock_status->getStockStatuses();

		$stats_file = DIR_STORAGE . 'oaiq_chatgpt_feed/stats_0.json';
		$data['stats'] = is_file($stats_file) ? json_decode(file_get_contents($stats_file), true) : null;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view('extension/feed/oaiq_chatgpt_feed', $data));
	}

	public function install() {
		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('feed_oaiq_chatgpt_feed', array(
			self::P . 'status' => 0,
			self::P . 'key'    => bin2hex(random_bytes(16)),
			self::P . 'format' => 'csv',
			self::P . 'delta_key' => bin2hex(random_bytes(32)),
		));
		$this->registerEvents();
	}

	/** Also called on every save, so updating the module over an old version needs no reinstall. */
	private function registerEvents() {
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('oaiq_chatgpt_feed');
		$this->model_setting_event->deleteEventByCode('oaiq_chatgpt_feed_auto');
		$this->model_setting_event->addEvent('oaiq_chatgpt_feed', 'admin/model/catalog/product/editProduct/after', 'extension/feed/oaiq_chatgpt_feed/eventProductEdit');
		// Keeps the feed fresh from normal storefront traffic: no cron needed.
		$this->model_setting_event->addEvent('oaiq_chatgpt_feed_auto', 'catalog/controller/common/footer/after', 'extension/feed/oaiq_chatgpt_feed/auto');
	}

	/** After a product is saved in admin, push its new price/stock through the Delta API. */
	public function eventProductEdit(&$route, &$args, &$output) {
		if (!$this->config->get(self::P . 'status') || !$this->config->get(self::P . 'delta_status') || empty($args[0])) {
			return;
		}
		$catalog = rtrim(HTTPS_CATALOG ? HTTPS_CATALOG : HTTP_CATALOG, '/') . '/';
		$url = $catalog . 'index.php?route=extension/feed/oaiq_chatgpt_feed/delta&dkey=' . urlencode((string)$this->config->get(self::P . 'delta_key')) . '&product_id=' . (int)$args[0];

		$ch = curl_init($url);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25));
		$body = curl_exec($ch);
		curl_close($ch);

		$res = json_decode((string)$body, true);
		if (!$res || !empty($res['error']) || (isset($res['http']) && $res['http'] != 200)) {
			$log = new Log('oaiq_chatgpt_feed.log');
			$log->write('Delta from admin, product ' . (int)$args[0] . ': ' . substr((string)$body, 0, 500));
		}
	}

	public function uninstall() {
		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('feed_oaiq_chatgpt_feed');
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('oaiq_chatgpt_feed');
		$this->model_setting_event->deleteEventByCode('oaiq_chatgpt_feed_auto');
	}

	/** Keep only known settings, with allowed values. */
	private function sanitize(array $in) {
		$out = array();
		foreach ($this->fields as $name => $default) {
			$k = self::P . $name;
			$v = isset($in[$k]) ? $in[$k] : $default;
			if (is_array($default)) {
				$v = is_array($v) ? $v : array();
			} elseif (is_array($v)) {
				$v = $default;
			} else {
				$v = trim((string)$v);
			}
			$out[$k] = $v;
		}
		$p = self::P;
		if (!preg_match('/^[a-f0-9]{32}$/', $out[$p . 'key'])) {
			$out[$p . 'key'] = bin2hex(random_bytes(16));
		}
		$old = (string)$this->config->get($p . 'delta_key');
		$out[$p . 'delta_key'] = preg_match('/^[a-f0-9]{64}$/', $old) ? $old : bin2hex(random_bytes(32));
		if ($out[$p . 'delta_api_key'] === '') {
			$out[$p . 'delta_api_key'] = (string)$this->config->get($p . 'delta_api_key');
		}
		if (!in_array($out[$p . 'format'], array('csv', 'tsv', 'jsonl'), true)) {
			$out[$p . 'format'] = 'csv';
		}
		if (!in_array($out[$p . 'id_source'], array('product_id', 'sku', 'model'), true)) {
			$out[$p . 'id_source'] = 'product_id';
		}
		foreach (array('status', 'with_tax', 'ads_eligible', 'delta_status') as $f) {
			$out[$p . $f] = $out[$p . $f] ? 1 : 0;
		}
		foreach (array('cache_hours', 'image_size') as $f) {
			$out[$p . $f] = max(0, min(10000, (int)$out[$p . $f]));
		}
		$out[$p . 'categories'] = array_values(array_filter(array_map('intval', $out[$p . 'categories'])));
		$out[$p . 'variant_options'] = array_values(array_filter(array_map('intval', $out[$p . 'variant_options'])));
		$allowed = array('in_stock', 'out_of_stock', 'pre_order', 'backorder', 'unknown');
		$map = array();
		foreach ($out[$p . 'stock_map'] as $id => $val) {
			if (in_array($val, $allowed, true)) {
				$map[(int)$id] = $val;
			}
		}
		$out[$p . 'stock_map'] = $map;
		$out[$p . 'store_country'] = preg_match('/^[A-Za-z]{2}$/', $out[$p . 'store_country']) ? strtoupper($out[$p . 'store_country']) : 'US';
		$out[$p . 'target_countries'] = strtoupper(preg_replace('/[^A-Za-z,]/', '', $out[$p . 'target_countries']));
		$out[$p . 'delta_feed_id'] = preg_replace('/[^A-Za-z0-9_\-]/', '', $out[$p . 'delta_feed_id']);
		$out[$p . 'exclude_ids'] = preg_replace('/[^0-9,\s]/', '', $out[$p . 'exclude_ids']);
		$out[$p . 'shipping_price'] = $out[$p . 'shipping_price'] === '' ? '' : (string)(float)str_replace(',', '.', $out[$p . 'shipping_price']);
		$out[$p . 'return_days'] = $out[$p . 'return_days'] === '' ? '' : (string)max(0, (int)$out[$p . 'return_days']);

		return $out;
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/feed/oaiq_chatgpt_feed')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		$p = $this->request->post;
		foreach (array('return_policy', 'privacy_url', 'tos_url') as $f) {
			$v = isset($p[self::P . $f]) ? trim((string)$p[self::P . $f]) : '';
			if ($v !== '' && !preg_match('#^https://[^\s/]+\.[^\s]+$#i', $v)) {
				$this->error['warning'] = sprintf($this->language->get('error_https'), $f);
			}
		}
		if (empty($p[self::P . 'seller_name'])) {
			$this->error['warning'] = $this->language->get('error_seller');
		}

		return !$this->error;
	}
}
