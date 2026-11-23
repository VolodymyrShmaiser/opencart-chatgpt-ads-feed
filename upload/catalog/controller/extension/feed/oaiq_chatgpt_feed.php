<?php
/**
 * ChatGPT Ads product feed (OpenAI product feed spec) for OpenCart 3.0.x.
 *
 * Feed:  index.php?route=extension/feed/oaiq_chatgpt_feed&key=KEY
 * Cron:  index.php?route=extension/feed/oaiq_chatgpt_feed/generate&key=KEY
 */
class ControllerExtensionFeedOaiqChatgptFeed extends Controller {
	const BATCH = 200;
	const P = 'feed_oaiq_chatgpt_feed_';

	private $errors = array();
	private $weight_units = array();

	public function index() {
		if (!$this->authorized()) {
			return;
		}

		$file = $this->feedFile();
		$ttl = max(1, (int)$this->cfg('cache_hours', 24)) * 3600;
		$stale = !is_file($file) || filemtime($file) < time() - $ttl;

		if (!is_file($file)) {
			// First request ever: build as much as fits in this request.
			$progress = $this->buildStep($this->timeBudget());
			if (empty($progress['done'])) {
				$this->response->addHeader('HTTP/1.1 503 Service Unavailable');
				$this->response->addHeader('Retry-After: 120');
				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($progress));
				return;
			}
			$stale = false;
		}

		$format = $this->format();
		$types = array('csv' => 'text/csv', 'tsv' => 'text/tab-separated-values', 'jsonl' => 'application/x-ndjson');

		// Always answer immediately with the last complete file, streamed from disk.
		while (ob_get_level()) {
			ob_end_clean();
		}
		header('Content-Type: ' . $types[$format] . '; charset=utf-8');
		header('Content-Length: ' . filesize($file));
		header('Content-Disposition: inline; filename="chatgpt-feed.' . $format . '"');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');
		readfile($file);

		// Stale file: keep rebuilding in the background after the client got its response (PHP-FPM).
		if ($stale && function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
			ignore_user_abort(true);
			$this->buildStep($this->timeBudget());
		}
		exit;
	}

	/**
	 * Cron entry point. Each call continues the build for up to ~50 s and reports progress,
	 * so large catalogs work even with a short max_execution_time. Run it every 5 minutes:
	 * it does nothing while the file is fresh.
	 */
	public function generate() {
		if (!$this->authorized()) {
			return;
		}

		// A fatal error (time limit, memory) must still answer with JSON the admin page can show.
		register_shutdown_function(function () {
			$e = error_get_last();
			if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
				if (!headers_sent()) {
					header('Content-Type: application/json');
				}
				echo json_encode(array('done' => false, 'error' => 'PHP error: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')'));
			}
		});

		$file = $this->feedFile();
		$ttl = max(1, (int)$this->cfg('cache_hours', 24)) * 3600;
		$force = !empty($this->request->get['force']) && (!is_file($file) || filemtime($file) < time() - 300);

		if (!$this->buildRunning() && is_file($file) && filemtime($file) > time() - $ttl && !$force) {
			$result = array('done' => true, 'fresh' => true, 'generated' => date('c', filemtime($file)));
			if (!empty($this->request->get['force'])) {
				// Fresh file but maybe never published (e.g. after an update): publish it now.
				$pub = $this->publish($file);
				$stats_file = $this->feedDir() . 'stats_' . (int)$this->config->get('config_store_id') . '.json';
				$stats = is_file($stats_file) ? (array)json_decode(file_get_contents($stats_file), true) : array();
				$stats['published'] = isset($pub['path']) ? $pub['path'] : null;
				$stats['publish_error'] = isset($pub['error']) ? $pub['error'] : null;
				file_put_contents($stats_file, json_encode($stats));
				$result = array_merge($result, array('fresh' => false, 'written' => isset($stats['written']) ? $stats['written'] : 0, 'skipped' => isset($stats['skipped']) ? $stats['skipped'] : 0, 'seconds' => 0, 'generated' => isset($stats['generated']) ? $stats['generated'] : '', 'errors' => isset($stats['errors']) ? $stats['errors'] : array(), 'published' => $stats['published'], 'publish_error' => $stats['publish_error']));
			}
		} else {
			ignore_user_abort(true);
			$result = $this->buildStep($this->timeBudget(), $force);

			// Not finished within this request's time limit: hand over to a fresh request,
			// so a single daily cron call completes the whole build on any catalog size.
			if (empty($result['done']) && empty($result['error'])) {
				$this->continueInNewRequest();
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($result));
	}

	/**
	 * Copies the finished feed to a static file in the site root: /chatgpt-feed/KEY.csv
	 * Ads Manager accepts only URLs without query parameters, and a static file is served
	 * by the web server directly — no PHP, no load, any catalog size.
	 */
	/**
	 * Copies the finished feed to a static file: /chatgpt-feed/KEY.csv in the site root,
	 * or /image/chatgpt-feed/KEY.csv when the root is not writable (image/ always is in OpenCart).
	 * Ads Manager accepts only URLs without query parameters, and a static file is served
	 * by the web server directly. Returns the path relative to the shop URL, or an error.
	 */
	private function publish($file) {
		$name = (int)$this->config->get('config_store_id') ? 's' . (int)$this->config->get('config_store_id') . '-' : '';
		$name .= preg_replace('/[^a-f0-9]/', '', (string)$this->cfg('key')) . '.' . $this->format();

		$targets = array(
			'chatgpt-feed/'       => rtrim(dirname(DIR_APPLICATION), '/' . DIRECTORY_SEPARATOR) . '/chatgpt-feed/',
			'image/chatgpt-feed/' => rtrim(DIR_IMAGE, '/' . DIRECTORY_SEPARATOR) . '/chatgpt-feed/',
		);
		$problems = array();

		foreach ($targets as $rel => $dir) {
			if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
				$problems[] = 'cannot create ' . $dir;
				continue;
			}
			if (!is_file($dir . 'index.html')) {
				@file_put_contents($dir . 'index.html', '');
			}
			if (!@copy($file, $dir . $name . '.tmp') || !@rename($dir . $name . '.tmp', $dir . $name)) {
				$problems[] = 'cannot write ' . $dir . $name;
				continue;
			}

			// Remove files left from an old key or format, and the copy in the other location.
			foreach ($targets as $other_dir) {
				foreach (array('csv', 'tsv', 'jsonl') as $ext) {
					foreach ((array)glob($other_dir . '*.' . $ext) as $old) {
						$base = basename($old);
						$same_store = (int)$this->config->get('config_store_id') ? strpos($base, substr($name, 0, strpos($name, '-') + 1)) === 0 : !preg_match('/^s\d+-/', $base);
						if ($old && $same_store && $old != $dir . $name) {
							@unlink($old);
						}
					}
				}
			}

			return array('path' => $rel . $name);
		}

		$this->log('Publish failed: ' . implode('; ', $problems));

		return array('error' => implode('; ', $problems));
	}

	/**
	 * Event catalog/controller/common/footer/after: keeps the feed fresh without any cron.
	 * Costs two filemtime() calls per page view; when the feed is stale, the build runs
	 * after the visitor's page has been sent (PHP-FPM) or in a separate self-request.
	 */
	public function auto(&$route = '', &$args = array(), &$output = null) {
		if (!$this->cfg('status') || strlen((string)$this->cfg('key')) < 16) {
			return;
		}

		$file = $this->feedFile();
		$ttl = max(1, (int)$this->cfg('cache_hours', 24)) * 3600;
		if (is_file($file) && filemtime($file) > time() - $ttl && !$this->buildRunning()) {
			return;
		}

		// At most one trigger per 2 minutes.
		$tick = $this->feedDir() . 'auto.tick';
		if (is_file($tick) && filemtime($tick) > time() - 120) {
			return;
		}
		@touch($tick);

		register_shutdown_function(function () {
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
				ignore_user_abort(true);
				$result = $this->buildStep($this->timeBudget());
				if (empty($result['done']) && empty($result['error'])) {
					$this->continueInNewRequest();
				}
			} else {
				$this->continueInNewRequest();
			}
		});
	}

	private function continueInNewRequest() {
		$base = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$url = rtrim($base, '/') .'/index.php?route=extension/feed/oaiq_chatgpt_feed/generate&key=' . urlencode((string)$this->cfg('key'));

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOSIGNAL       => true,
			CURLOPT_TIMEOUT_MS     => 1000, // fire and forget: the next step keeps running on the server
		));
		curl_exec($ch);
		curl_close($ch);
	}

	/**
	 * Delta API: push title/price/availability of given products to ChatGPT Ads.
	 * index.php?route=extension/feed/oaiq_chatgpt_feed/delta&key=KEY&product_id=1,2,3
	 */
	public function delta() {
		$secret = (string)$this->cfg('delta_key');
		if (!$this->cfg('status') || strlen($secret) < 32 || !isset($this->request->get['dkey'])
			|| !hash_equals($secret, (string)$this->request->get['dkey'])) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('Forbidden');
			return;
		}
		$this->response->addHeader('Content-Type: application/json');

		$api_key = $this->cfg('delta_api_key');
		$feed_id = $this->cfg('delta_feed_id');
		if (!$this->cfg('delta_status') || !$api_key || !$feed_id) {
			$this->response->setOutput(json_encode(array('error' => 'Delta API is disabled or not configured')));
			return;
		}

		$ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', isset($this->request->get['product_id']) ? (string)$this->request->get['product_id'] : '')))), 0, 100);
		if (!$ids) {
			$this->response->setOutput(json_encode(array('error' => 'product_id required')));
			return;
		}

		$this->load->model('extension/feed/oaiq_chatgpt_feed');
		$this->load->model('tool/image');
		$values = $this->model_extension_feed_oaiq_chatgpt_feed->getVariantValues($ids, $this->variantOptionIds());

		$groups = array();
		foreach ($this->model_extension_feed_oaiq_chatgpt_feed->getProducts($ids) as $p) {
			if (!$p['status']) {
				continue; // disabled products: let the next full upload drop them
			}
			foreach ($this->itemsFor($p, isset($values[$p['product_id']]) ? $values[$p['product_id']] : array()) as $item) {
				list($amount, $currency) = explode(' ', $item['price']);
				$gid = $item['group_id'] ? $item['group_id'] : $item['item_id'];
				$groups[$gid][] = array(
					'id'           => $item['item_id'],
					'title'        => $item['title'],
					'price'        => array('amount' => (int)round((float)$amount * 100), 'currency' => $currency),
					'availability' => array('status' => $item['availability']),
				);
			}
		}

		$products = array();
		foreach ($groups as $gid => $variants) {
			$products[] = array('id' => (string)$gid, 'variants' => $variants);
		}
		if (!$products) {
			$this->response->setOutput(json_encode(array('error' => 'no products found')));
			return;
		}

		$ch = curl_init('https://api.ads.openai.com/v1/feeds/' . rawurlencode($feed_id) . '/products');
		curl_setopt_array($ch, array(
			CURLOPT_CUSTOMREQUEST  => 'PATCH',
			CURLOPT_POSTFIELDS     => json_encode(array('products' => $products), JSON_UNESCAPED_UNICODE),
			CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json'),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
		));
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);

		$this->log('Delta ' . implode(',', $ids) . ': HTTP ' . $code . ' ' . ($err ? $err : substr((string)$body, 0, 500)));
		$this->response->setOutput(json_encode(array('http' => $code, 'response' => json_decode((string)$body, true), 'error' => $err ? $err : null)));
	}

	private function authorized() {
		if (!$this->cfg('status') || strlen((string)$this->cfg('key')) < 16 || !isset($this->request->get['key'])
			|| !hash_equals((string)$this->cfg('key'), (string)$this->request->get['key'])) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('Forbidden');
			return false;
		}

		return true;
	}

	private function cfg($name, $default = null) {
		$v = $this->config->get(self::P . $name);

		return ($v === null || $v === '') ? $default : $v;
	}

	private function feedDir() {
		$dir = DIR_STORAGE . 'oaiq_chatgpt_feed/';
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		return $dir;
	}

	private function feedFile() {
		return $this->feedDir() . 'feed_' . (int)$this->config->get('config_store_id') . '.' . $this->format();
	}

	private function format() {
		$f = $this->cfg('format', 'csv');

		return in_array($f, array('csv', 'tsv', 'jsonl'), true) ? $f : 'csv';
	}

	private function columns() {
		return array(
			'item_id', 'title', 'description', 'url', 'brand', 'seller_name', 'seller_url', 'image_url', 'additional_image_urls',
			'price', 'sale_price', 'availability', 'target_countries', 'store_country', 'condition', 'product_category',
			'group_id', 'listing_has_variations', 'variant_dict', 'gtin', 'mpn', 'weight', 'item_weight_unit', 'shipping_price', 'return_policy', 'accepts_returns',
			'return_deadline_in_days', 'seller_privacy_policy', 'seller_tos', 'is_eligible_search', 'is_eligible_checkout',
			'is_ads_eligible', 'ads_metadata'
		);
	}

	private function statePath() {
		return $this->feedDir() . 'state_' . (int)$this->config->get('config_store_id') . '.json';
	}

	private function buildRunning() {
		$state = $this->statePath();

		return is_file($state) && filemtime($state) > time() - 86400;
	}

	/** Seconds one request may spend building: below max_execution_time, capped at 50. */
	private function timeBudget() {
		$max = (int)ini_get('max_execution_time');
		@set_time_limit(0);
		if ((int)ini_get('max_execution_time') === 0) {
			return 25;
		}

		return max(5, min(25, ($max ? $max : 30) - 10));
	}

	/**
	 * Resumable build. Writes to a .tmp file and keeps its position (last product_id) in a state file,
	 * so the work is split across as many requests as needed. The live file is swapped only when complete.
	 */
	private function buildStep($budget, $restart = false) {
		$lock = fopen($this->feedDir() . 'build.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			return array('done' => false, 'error' => 'build already running in another request');
		}

		$t0 = microtime(true);
		$this->load->model('extension/feed/oaiq_chatgpt_feed');
		$this->load->model('tool/image');

		$format = $this->format();
		$file = $this->feedFile();
		$tmp = $file . '.tmp';
		$state_file = $this->statePath();
		$state = (!$restart && $this->buildRunning()) ? json_decode(file_get_contents($state_file), true) : null;

		if (!$state || !is_file($tmp) || (isset($state['format']) && $state['format'] != $format)) {
			$state = array('format' => $format, 'last_id' => 0, 'written' => 0, 'skipped' => 0, 'seconds' => 0, 'started' => date('c'), 'errors' => array());
			$fh = fopen($tmp, 'w');
			if ($fh && $format != 'jsonl') {
				fputcsv($fh, $this->columns(), $format == 'tsv' ? "\t" : ',');
			}
		} else {
			$fh = fopen($tmp, 'a');
		}
		if (!$fh) {
			$this->log('Cannot write ' . $tmp);
			flock($lock, LOCK_UN);
			return array('done' => false, 'error' => 'cannot write feed file');
		}

		$cols = $this->columns();
		$sep = $format == 'tsv' ? "\t" : ',';
		$categories = (array)$this->cfg('categories', array());
		$excluded = array_flip(array_map('intval', preg_split('/[\s,]+/', (string)$this->cfg('exclude_ids', ''), -1, PREG_SPLIT_NO_EMPTY)));
		$done = false;

		while (microtime(true) - $t0 < $budget) {
			// Keyset pagination (product_id > last) stays fast on any catalog size, unlike LIMIT offset.
			$ids = $this->model_extension_feed_oaiq_chatgpt_feed->getProductIds($state['last_id'], self::BATCH, $categories);
			if (!$ids) {
				$done = true;
				break;
			}

			$variant_values = $this->model_extension_feed_oaiq_chatgpt_feed->getVariantValues($ids, $this->variantOptionIds());
			$products = $this->model_extension_feed_oaiq_chatgpt_feed->getProducts($ids);

			$out_of_time = false;
			foreach ($ids as $id) {
				// Image conversion can be slow: stop between products, not only between batches.
				if (microtime(true) - $t0 >= $budget) {
					$out_of_time = true;
					break;
				}
				$state['last_id'] = (int)$id;
				if (!isset($products[$id]) || isset($excluded[(int)$id])) {
					continue;
				}
				$product = $products[$id];
				$values = isset($variant_values[$id]) ? $variant_values[$id] : array();

				foreach ($this->itemsFor($product, $values) as $item) {
					$problems = $this->validate($item);
					if ($problems) {
						$state['skipped']++;
						$msg = 'product_id ' . $id . ' (' . $item['item_id'] . '): ' . implode('; ', $problems);
						$this->log($msg);
						if (count($state['errors']) < 50) {
							$state['errors'][] = $msg;
						}
						continue;
					}

					if ($format == 'jsonl') {
						fwrite($fh, json_encode($this->toJson($item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
					} else {
						$row = array();
						foreach ($cols as $c) {
							$row[] = $this->toCell(isset($item[$c]) ? $item[$c] : null);
						}
						fputcsv($fh, $row, $sep);
					}
					$state['written']++;
				}
			}

			unset($products, $variant_values);
			$this->weight_units = array();
			if ($out_of_time) {
				break;
			}
		}

		fclose($fh);
		$state['seconds'] = round($state['seconds'] + microtime(true) - $t0, 2);

		if ($done) {
			rename($tmp, $file);
			$published = $this->publish($file);
			@unlink($state_file);
			$stats = array(
				'done'      => true,
				'generated' => date('c'),
				'written'   => $state['written'],
				'skipped'   => $state['skipped'],
				'seconds'   => $state['seconds'],
				'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
				'errors'    => $state['errors'],
				'published' => isset($published['path']) ? $published['path'] : null,
				'publish_error' => isset($published['error']) ? $published['error'] : null,
			);
			file_put_contents($this->feedDir() . 'stats_' . (int)$this->config->get('config_store_id') . '.json', json_encode($stats));
			$this->log(sprintf('Built %s: %d written, %d skipped, %ss', basename($file), $stats['written'], $stats['skipped'], $stats['seconds']));
		} else {
			file_put_contents($state_file, json_encode($state));
			$stats = array('done' => false, 'last_product_id' => $state['last_id'], 'written' => $state['written'], 'skipped' => $state['skipped'], 'seconds' => $state['seconds']);
		}

		flock($lock, LOCK_UN);
		fclose($lock);

		return $stats;
	}

	private function mapProduct(array $p) {
		$currency = $this->cfg('currency', $this->config->get('config_currency'));
		$country = strtoupper($this->cfg('store_country', 'US'));
		$base = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : HTTPS_SERVER;

		switch ($this->cfg('id_source', 'product_id')) {
			case 'sku':   $item_id = $p['sku'] !== '' ? $p['sku'] : $p['product_id']; break;
			case 'model': $item_id = $p['model'] !== '' ? $p['model'] : $p['product_id']; break;
			default:      $item_id = $p['product_id'];
		}

		$price = $this->money($p['price'], $p['tax_class_id'], $currency);
		$sale = ($p['special'] !== null && (float)$p['special'] > 0 && (float)$p['special'] < (float)$p['price'])
			? $this->money($p['special'], $p['tax_class_id'], $currency) : null;

		if ($p['quantity'] > 0) {
			$availability = 'in_stock';
		} elseif (!$p['subtract']) {
			$availability = 'in_stock';
		} else {
			$map = (array)$this->cfg('stock_map', array());
			$availability = isset($map[$p['stock_status_id']]) ? $map[$p['stock_status_id']] : 'out_of_stock';
		}

		$images = array();
		foreach ($p['images'] as $img) {
			if ($img) {
				$images[] = $this->imageUrl($img, $base);
			}
		}

		$desc = $this->cleanText($p['description']);
		$title = $this->cleanText($p['name']);
		if ($desc === '') {
			$desc = $title;
		}

		$brand = $p['manufacturer'] ? html_entity_decode($p['manufacturer'], ENT_QUOTES, 'UTF-8') : $this->cfg('default_brand', $this->config->get('config_name'));

		$gtin = '';
		foreach (array('ean', 'upc', 'jan', 'isbn') as $f) {
			$digits = preg_replace('/\D/', '', (string)$p[$f]);
			if (in_array(strlen($digits), array(8, 12, 13, 14))) {
				$gtin = $digits;
				break;
			}
		}

		$weight = null;
		$weight_unit = null;
		if ((float)$p['weight'] > 0) {
			$unit = $this->weightUnit($p['weight_class_id']);
			if ($unit) {
				$weight = rtrim(rtrim(number_format((float)$p['weight'], 3, '.', ''), '0'), '.');
				$weight_unit = $unit;
			}
		}

		$labels = array();
		if ($p['category'] !== '') {
			$parts = explode(' > ', $p['category']);
			$labels['custom_label_0'] = $parts[0];
		}
		if ($sale) {
			$labels['custom_label_1'] = 'on_sale';
		}
		if ($p['manufacturer']) {
			$labels['custom_label_2'] = $brand;
		}

		$return_days = $this->cfg('return_days');

		return array(
			'item_id'                 => (string)$item_id,
			'title'                   => mb_substr($title, 0, 150),
			'description'             => mb_substr($desc, 0, 5000),
			'url'                     => str_replace('&amp;', '&', $this->url->link('product/product', 'product_id=' . $p['product_id'], true)),
			'brand'                   => mb_substr($brand, 0, 70),
			'seller_name'             => $this->cfg('seller_name', $this->config->get('config_name')),
			'seller_url'              => $base,
			'image_url'               => $p['image'] ? $this->imageUrl($p['image'], $base) : '',
			'additional_image_urls'   => $images,
			'price'                   => $price,
			'sale_price'              => $sale,
			'availability'            => $availability,
			'target_countries'        => array_values(array_filter(array_map('trim', explode(',', strtoupper($this->cfg('target_countries', $country)))))),
			'store_country'           => $country,
			'condition'               => 'new',
			'product_category'        => $p['category'] !== '' ? $p['category'] : null,
			'group_id'                => null,
			'gtin'                    => $gtin !== '' ? $gtin : null,
			'mpn'                     => $p['mpn'] !== '' ? $p['mpn'] : null,
			'weight'                  => $weight,
			'item_weight_unit'        => $weight_unit,
			'shipping_price'          => $this->cfg('shipping_price') !== null ? number_format((float)$this->cfg('shipping_price'), 2, '.', '') . ' ' . $currency : null,
			'return_policy'           => $this->cfg('return_policy'),
			'accepts_returns'         => $return_days !== null ? true : null,
			'return_deadline_in_days' => $return_days !== null ? (int)$return_days : null,
			'seller_privacy_policy'   => $this->cfg('privacy_url'),
			'seller_tos'              => $this->cfg('tos_url'),
			'is_eligible_search'      => true,
			'is_eligible_checkout'    => false,
			'is_ads_eligible'         => (bool)$this->cfg('ads_eligible', 1),
			'ads_metadata'            => $labels ? $labels : null,
		);
	}

	private function variantOptionIds() {
		return array_map('intval', (array)$this->cfg('variant_options', array()));
	}

	/**
	 * One feed row per purchasable variant (cartesian product of the selected options),
	 * or a single row when the product has none of them.
	 */
	private function itemsFor(array $p, array $values) {
		$base = $this->mapProduct($p);
		if (!$values) {
			return array($base);
		}

		$combos = array(array());
		foreach ($values as $option_values) {
			$next = array();
			foreach ($combos as $combo) {
				foreach ($option_values as $v) {
					$next[] = array_merge($combo, array($v));
					if (count($next) >= 100) {
						break 2;
					}
				}
			}
			$combos = $next;
		}

		$base_base = $this->config->get('config_ssl') ? $this->config->get('config_ssl') : HTTPS_SERVER;
		$items = array();
		foreach ($combos as $combo) {
			$q = $p;
			$delta = 0.0;
			$dict = array();
			$ids = array();
			$out = false;
			$image = '';
			foreach ($combo as $v) {
				$price = (float)$v['price'];
				if ($v['price_prefix'] == '+') {
					$delta += $price;
				} elseif ($v['price_prefix'] == '-') {
					$delta -= $price;
				}
				$dict[html_entity_decode($v['option_name'], ENT_QUOTES, 'UTF-8')] = html_entity_decode($v['value_name'], ENT_QUOTES, 'UTF-8');
				$ids[] = $v['product_option_value_id'];
				if ($v['subtract'] && $v['quantity'] <= 0) {
					$out = true;
				}
				if (!$image && $v['image'] && $v['image'] != 'no_image.png') {
					$image = $v['image'];
				}
			}
			$q['price'] = (float)$p['price'] + $delta;
			if ($p['special'] !== null) {
				$q['special'] = (float)$p['special'] + $delta;
			}
			$item = $this->mapProduct($q);
			$item['item_id'] = $base['item_id'] . '-' . implode('-', $ids);
			$item['group_id'] = (string)$base['item_id'];
			$item['listing_has_variations'] = true;
			$item['variant_dict'] = $dict;
			$item['title'] = mb_substr($base['title'] . ' ' . implode(' ', $dict), 0, 150);
			if ($out) {
				$item['availability'] = 'out_of_stock';
			}
			if ($image) {
				if ($item['image_url']) {
					array_unshift($item['additional_image_urls'], $item['image_url']);
				}
				$item['image_url'] = $this->imageUrl($image, $base_base);
			}
			$items[] = $item;
		}

		return $items;
	}

	/** Returns a list of problems; empty means the row is valid. */
	private function validate(array $i) {
		$problems = array();

		foreach (array('item_id', 'title', 'description', 'url', 'brand', 'seller_name', 'image_url', 'price', 'availability', 'store_country') as $f) {
			if (!isset($i[$f]) || $i[$f] === '' || $i[$f] === null) {
				$problems[] = 'missing ' . $f;
			}
		}
		if (empty($i['target_countries'])) {
			$problems[] = 'missing target_countries';
		}
		foreach (array('url', 'image_url') as $f) {
			if (!empty($i[$f]) && stripos($i[$f], 'https://') !== 0) {
				$problems[] = $f . ' is not HTTPS';
			}
		}
		if (!empty($i['image_url']) && !preg_match('/\.(jpe?g|png)(\?|$)/i', $i['image_url'])) {
			$problems[] = 'image_url is not JPEG/PNG and could not be converted (PHP GD without WebP support?)';
		}
		if (!empty($i['price']) && !preg_match('/^\d+(\.\d{1,2})? [A-Z]{3}$/', $i['price'])) {
			$problems[] = 'invalid price ' . $i['price'];
		}
		if (preg_match('/^0(\.0+)? /', (string)$i['price'])) {
			$problems[] = 'price is zero';
		}

		return $problems;
	}

	private function money($amount, $tax_class_id, $currency) {
		if ($this->cfg('with_tax', 1)) {
			$amount = $this->tax->calculate($amount, $tax_class_id, true);
		}
		$value = $this->currency->format($amount, $currency, '', false);

		return number_format((float)$value, 2, '.', '') . ' ' . $currency;
	}

	private function imageUrl($path, $base) {
		$root = rtrim(preg_replace('#^http://#i', 'https://', $base), '/') . '/image/';
		$w = (int)$this->cfg('image_size', 0);

		// OpenAI wants JPEG or PNG. WebP/GIF/etc. are converted once to JPEG and cached.
		if (!preg_match('/\.(jpe?g|png)$/i', $path)) {
			$jpeg = $this->toJpeg($path, $w);
			if ($jpeg) {
				return $root . implode('/', array_map('rawurlencode', explode('/', $jpeg)));
			}
		} elseif ($w > 0) {
			$url = $this->model_tool_image->resize($path, $w, $w);
			if (preg_match('/\.(jpe?g|png)$/i', $url)) {
				return preg_replace('#^http://#i', 'https://', $url);
			}
		}

		return $root . implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	/**
	 * Converts an image under image/ to JPEG in image/cache/chatgpt-feed/ (GD). Returns the path
	 * relative to image/, or false when GD cannot read the format.
	 */
	private function toJpeg($path, $max = 0) {
		$src = DIR_IMAGE . $path;
		if (!is_file($src) || strpos((string)realpath($src), (string)realpath(DIR_IMAGE)) !== 0) {
			return false;
		}

		$rel = 'cache/chatgpt-feed/' . preg_replace('/\.[a-z0-9]+$/i', '', $path) . ($max ? '-' . $max : '') . '.jpg';
		$dst = DIR_IMAGE . $rel;
		if (is_file($dst) && filemtime($dst) >= filemtime($src)) {
			return $rel;
		}

		$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
		$img = false;
		if ($ext == 'webp' && function_exists('imagecreatefromwebp')) {
			$img = @imagecreatefromwebp($src);
		} elseif ($ext == 'gif' && function_exists('imagecreatefromgif')) {
			$img = @imagecreatefromgif($src);
		} elseif (function_exists('imagecreatefromstring')) {
			$img = @imagecreatefromstring(file_get_contents($src));
		}
		if (!$img) {
			return false;
		}

		$w = imagesx($img);
		$h = imagesy($img);
		$scale = ($max && max($w, $h) > $max) ? $max / max($w, $h) : 1;
		$nw = max(1, (int)round($w * $scale));
		$nh = max(1, (int)round($h * $scale));

		// White background: JPEG has no transparency.
		$out = imagecreatetruecolor($nw, $nh);
		imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
		imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

		if (!is_dir(dirname($dst))) {
			@mkdir(dirname($dst), 0755, true);
		}
		$ok = @imagejpeg($out, $dst, 90);
		imagedestroy($img);
		imagedestroy($out);

		return $ok ? $rel : false;
	}

	private function weightUnit($id) {
		if (!isset($this->weight_units[$id])) {
			$unit = $this->model_extension_feed_oaiq_chatgpt_feed->getWeightUnit($id);
			$aliases = array('kg' => 'kg', 'g' => 'g', 'lb' => 'lb', 'lbs' => 'lb', 'oz' => 'oz', 'кг' => 'kg', 'г' => 'g');
			$this->weight_units[$id] = isset($aliases[$unit]) ? $aliases[$unit] : '';
		}

		return $this->weight_units[$id];
	}

	private function cleanText($html) {
		$text = html_entity_decode((string)$html, ENT_QUOTES, 'UTF-8');
		$text = preg_replace('#<(br|/p|/div|/li|/h\d)[^>]*>#i', ' ', $text);
		$text = strip_tags($text);
		// OpenCart stores descriptions HTML-escaped, so entities like &nbsp; survive the first decode.
		$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
		$text = str_replace("\xC2\xA0", ' ', $text);

		return trim(preg_replace('/\s+/u', ' ', $text));
	}

	private function toCell($v) {
		if ($v === null) {
			return '';
		}
		if (is_bool($v)) {
			return $v ? 'true' : 'false';
		}
		if (is_array($v)) {
			if (!$v) {
				return '';
			}
			// Plain lists (URLs, country codes) → comma-separated, commas inside values escaped; objects → JSON.
			if (array_keys($v) === range(0, count($v) - 1)) {
				return implode(',', str_replace(',', '%2C', $v));
			}
			return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		return (string)$v;
	}

	private function toJson(array $item) {
		return array_filter($item, function ($v) {
			return $v !== null && $v !== '' && $v !== array();
		});
	}

	private function log($msg) {
		$msg = preg_replace('/[\r\n]+/', ' ', $msg);
		$log = new Log('oaiq_chatgpt_feed.log');
		$log->write($msg);
	}
}
