import json
import os
import shutil
import tempfile
import unittest

import render


def copy_tree(target):
    for rel in render.RENDERED + ['integrations/listing.json']:
        dest = os.path.join(target, rel)
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        shutil.copy(os.path.join(render.ROOT, rel), dest)


class ListingTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        copy_tree(self.tmp)

    def load(self, path):
        with open(path, encoding='utf-8') as handle:
            return json.load(handle)

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def test_tree_matches_source(self):
        self.assertEqual(render.drift(render.ROOT), [])

    def test_hand_edit_of_each_rendered_file_is_caught(self):
        for rel in render.RENDERED:
            path = os.path.join(self.tmp, rel)
            with open(path, encoding='utf-8') as handle:
                original = handle.read()
            rendered = render.render_all(self.tmp)[rel]
            self.assertEqual(rendered, original)
            with open(path, 'w', encoding='utf-8') as handle:
                handle.write(original.replace(render.load(self.tmp)['product']['php_min'], '8.1', 1) if rel in render.PHP_ONLY else original.replace('stablecoin', 'coin', 1) if 'stablecoin' in original else original + ' ')
            self.assertEqual(render.drift(self.tmp), [rel], rel)
            with open(path, 'w', encoding='utf-8') as handle:
                handle.write(original)

    def test_php_floor_reaches_every_metadata_file_and_readme(self):
        path = os.path.join(self.tmp, 'integrations/listing.json')
        with open(path, encoding='utf-8') as handle:
            data = json.load(handle)
        data['product']['php_min'] = '8.4'
        with open(path, 'w', encoding='utf-8') as handle:
            json.dump(data, handle)
        rendered = render.render_all(self.tmp)
        for rel in render.PHP_COMPOSERS:
            self.assertEqual(json.loads(rendered[rel])['require']['php'], '>=8.4', rel)
        for rel in render.PHP_DOCS:
            self.assertIn('PHP 8.4 or newer', rendered[rel], rel)
        self.assertIn('Requires PHP: 8.4', rendered[render.WP_README])
        self.assertIn('Requires PHP: 8.4', rendered[render.WP_PLUGIN])

    def test_php_floor_must_be_a_version(self):
        path = os.path.join(self.tmp, 'integrations/listing.json')
        with open(path, encoding='utf-8') as handle:
            data = json.load(handle)
        data['product']['php_min'] = '>=8.2'
        with open(path, 'w', encoding='utf-8') as handle:
            json.dump(data, handle)
        with self.assertRaises(render.ListingError):
            render.render_all(self.tmp)

    def test_wording_change_reaches_every_file_and_sheet(self):
        path = os.path.join(self.tmp, 'integrations/listing.json')
        with open(path, encoding='utf-8') as handle:
            data = json.load(handle)
        before = render.render_all(self.tmp)
        before_sheets = render.sheets(self.tmp)
        data['product']['tagline'] = 'Changed tagline'
        for values in data['platforms'].values():
            for key in ('short', 'long', 'title'):
                if key in values and key != 'long':
                    values[key] = values[key] + ' Changed'
                elif key in values:
                    values[key] = [values[key][0] + ' Changed'] + values[key][1:]
        data['platforms']['woocommerce_composer']['description'] = 'Changed description'
        data['platforms']['wordpress']['title'] = 'Changed'
        data['platforms']['magento']['title'] = 'Changed'
        with open(path, 'w', encoding='utf-8') as handle:
            json.dump(data, handle)
        after = render.render_all(self.tmp)
        for rel in render.RENDERED:
            if rel in render.PHP_DOCS and rel != render.MG_GUIDE:
                self.assertEqual(before[rel], after[rel], rel)
                continue
            self.assertNotEqual(before[rel], after[rel], rel)
        for name, text in render.sheets(self.tmp).items():
            self.assertNotEqual(before_sheets[name], text, name)

    def test_limits_are_enforced(self):
        path = os.path.join(self.tmp, 'integrations/listing.json')
        data = self.load(path)
        data['platforms']['mcp_registry']['short'] = 'x' * 101
        with open(path, 'w', encoding='utf-8') as handle:
            json.dump(data, handle)
        with self.assertRaises(render.ListingError):
            render.render_all(self.tmp)


if __name__ == '__main__':
    unittest.main()
