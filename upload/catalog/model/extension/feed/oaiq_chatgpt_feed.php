<?php
/**
 * ChatGPT Ads product feed — data access.
 * Reads products in batches so memory stays flat on large catalogs.
 */
class ModelExtensionFeedOaiqChatgptFeed extends Model {
	public function getProductIds($after_id, $limit, array $category_ids = array()) {
		$sql = "SELECT DISTINCT p.product_id FROM `" . DB_PREFIX . "product` p
			INNER JOIN `" . DB_PREFIX . "product_to_store` p2s ON (p.product_id = p2s.product_id AND p2s.store_id = '" . (int)$this->config->get('config_store_id') . "')";

		if ($category_ids) {
			$sql .= " INNER JOIN `" . DB_PREFIX . "product_to_category` p2c ON (p.product_id = p2c.product_id AND p2c.category_id IN (" . implode(',', array_map('intval', $category_ids)) . "))";
		}

		$sql .= " WHERE p.status = '1' AND p.date_available <= NOW() AND p.product_id > '" . (int)$after_id . "' ORDER BY p.product_id ASC LIMIT " . (int)$limit;

		return array_column($this->db->query($sql)->rows, 'product_id');
	}

	public function getProducts(array $ids) {
		if (!$ids) {
			return array();
		}

		$in = implode(',', array_map('intval', $ids));
		$lang = (int)$this->config->get('config_language_id');
		$cg = (int)$this->config->get('config_customer_group_id');

		$query = $this->db->query("SELECT p.*, pd.name, pd.description, m.name AS manufacturer,
			(SELECT ps.price FROM `" . DB_PREFIX . "product_special` ps WHERE ps.product_id = p.product_id AND ps.customer_group_id = '" . $cg . "'
				AND (ps.date_start = '0000-00-00' OR ps.date_start <= NOW()) AND (ps.date_end = '0000-00-00' OR ps.date_end > NOW())
				ORDER BY ps.priority ASC, ps.price ASC LIMIT 1) AS special
			FROM `" . DB_PREFIX . "product` p
			LEFT JOIN `" . DB_PREFIX . "product_description` pd ON (p.product_id = pd.product_id AND pd.language_id = '" . $lang . "')
			LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON (p.manufacturer_id = m.manufacturer_id)
			WHERE p.product_id IN (" . $in . ")");

		$products = array();
		foreach ($query->rows as $row) {
			$row['images'] = array();
			$row['category'] = '';
			$products[$row['product_id']] = $row;
		}

		$images = $this->db->query("SELECT product_id, image FROM `" . DB_PREFIX . "product_image` WHERE product_id IN (" . $in . ") ORDER BY sort_order ASC");
		foreach ($images->rows as $img) {
			if (isset($products[$img['product_id']]) && count($products[$img['product_id']]['images']) < 10) {
				$products[$img['product_id']]['images'][] = $img['image'];
			}
		}

		// Deepest category path per product, e.g. "Electronics > Phones".
		$cats = $this->db->query("SELECT p2c.product_id, GROUP_CONCAT(cd.name ORDER BY cp.level SEPARATOR ' > ') AS path, MAX(cp.level) AS depth
			FROM `" . DB_PREFIX . "product_to_category` p2c
			INNER JOIN `" . DB_PREFIX . "category_path` cp ON (cp.category_id = p2c.category_id)
			INNER JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = cp.path_id AND cd.language_id = '" . $lang . "')
			WHERE p2c.product_id IN (" . $in . ")
			GROUP BY p2c.product_id, p2c.category_id");
		$depth = array();
		foreach ($cats->rows as $c) {
			$pid = $c['product_id'];
			if (isset($products[$pid]) && (!isset($depth[$pid]) || $c['depth'] > $depth[$pid])) {
				$depth[$pid] = $c['depth'];
				$products[$pid]['category'] = html_entity_decode($c['path'], ENT_QUOTES, 'UTF-8');
			}
		}

		return $products;
	}

	/** Option values of the given options, grouped by product_id → option_id. */
	public function getVariantValues(array $product_ids, array $option_ids) {
		if (!$product_ids || !$option_ids) {
			return array();
		}
		$lang = (int)$this->config->get('config_language_id');

		$q = $this->db->query("SELECT pov.product_id, pov.option_id, pov.product_option_value_id, pov.quantity, pov.subtract, pov.price, pov.price_prefix,
				od.name AS option_name, ovd.name AS value_name, ov.image
			FROM `" . DB_PREFIX . "product_option_value` pov
			INNER JOIN `" . DB_PREFIX . "option_value` ov ON (ov.option_value_id = pov.option_value_id)
			INNER JOIN `" . DB_PREFIX . "option_value_description` ovd ON (ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . $lang . "')
			INNER JOIN `" . DB_PREFIX . "option_description` od ON (od.option_id = pov.option_id AND od.language_id = '" . $lang . "')
			WHERE pov.product_id IN (" . implode(',', array_map('intval', $product_ids)) . ")
				AND pov.option_id IN (" . implode(',', array_map('intval', $option_ids)) . ")
			ORDER BY pov.product_id, pov.option_id, ov.sort_order");

		$out = array();
		foreach ($q->rows as $r) {
			$out[$r['product_id']][$r['option_id']][] = $r;
		}

		return $out;
	}

	public function getWeightUnit($weight_class_id) {
		$q = $this->db->query("SELECT unit FROM `" . DB_PREFIX . "weight_class_description` WHERE weight_class_id = '" . (int)$weight_class_id . "' AND language_id = '" . (int)$this->config->get('config_language_id') . "'");

		return $q->num_rows ? strtolower($q->row['unit']) : '';
	}
}
