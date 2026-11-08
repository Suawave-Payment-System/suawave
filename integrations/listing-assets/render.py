import json
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))

WP_README = 'integrations/woocommerce/readme.txt'
WP_PLUGIN = 'integrations/woocommerce/suawave-payment.php'
WP_COMPOSER = 'integrations/woocommerce/composer.json'
MG_COMPOSER = 'integrations/magento/composer.json'
MG_GUIDE = 'integrations/magento/docs/USER_GUIDE.md'
PS_COMPOSER = 'integrations/prestashop/composer.json'
PS_MODULE = 'integrations/prestashop/suawave/suawave.php'
OC_COMPOSER = 'integrations/opencart/composer.json'
OC_INSTALL = 'integrations/opencart/upload/extension/suawave/install.json'
JS_PACKAGE = 'sdk/js/package.json'
MCP_PACKAGE = 'cmd/mcp/npm/package.json'
MCP_SERVER = 'cmd/mcp/npm/server.json'

RENDERED = [
    WP_README, WP_PLUGIN, WP_COMPOSER, MG_COMPOSER, MG_GUIDE, PS_COMPOSER,
    PS_MODULE, OC_COMPOSER, OC_INSTALL, JS_PACKAGE, MCP_PACKAGE, MCP_SERVER,
]

SHEETS = [
    ('wordpress', 'WordPress.org plugin directory'),
    ('prestashop', 'PrestaShop Addons'),
    ('magento', 'Adobe Commerce Marketplace'),
    ('opencart', 'OpenCart Marketplace'),
    ('npm_js', 'npm package @suawave/gateway-client'),
    ('npm_mcp', 'npm package @suawave/mcp'),
    ('mcp_registry', 'MCP registry'),
]


class ListingError(Exception):
    pass


def read(root, rel):
    with open(os.path.join(root, rel), encoding='utf-8') as handle:
        return handle.read()


def load(root):
    return json.loads(read(root, 'integrations/listing.json'))


def field(listing, platform, name):
    override = listing['platforms'].get(platform, {})
    if name in override:
        return override[name]
    if name == 'title':
        return listing['product']['name']
    return listing['product'][name]


def description(listing, platform):
    override = listing['platforms'].get(platform, {})
    return override.get('description') or field(listing, platform, 'short')


def validate(listing, root):
    problems = []
    version = json.loads(read(root, JS_PACKAGE))['version']
    if listing['product']['changelog'][0]['version'] != version:
        problems.append('changelog does not start with version ' + version)
    single = [('product', listing['product']['short'])]
    for platform, values in listing['platforms'].items():
        for key in ('title', 'short', 'description'):
            if key in values:
                single.append((platform + '.' + key, values[key]))
    for label, value in single:
        if '\n' in value or not value.strip():
            problems.append(label + ' must be one non-empty line')
    if len(field(listing, 'wordpress', 'short')) > 150:
        problems.append('wordpress short description is longer than 150 characters')
    if len(field(listing, 'mcp_registry', 'short')) > 100:
        problems.append('mcp_registry short description is longer than 100 characters')
    if len(field(listing, 'wordpress', 'tags')) > 5:
        problems.append('wordpress has more than 5 tags')
    if len(field(listing, 'magento', 'title').split()) > 4:
        problems.append('magento title is longer than 4 words')
    for platform, _ in SHEETS:
        for text in [field(listing, platform, 'short'), field(listing, platform, 'tagline')]:
            if re.search(r'cryptapi', text, re.I):
                problems.append(platform + ' text mentions cryptapi')
    if problems:
        raise ListingError('; '.join(problems))


def set_json_string(text, key, value):
    pattern = re.compile(r'("' + re.escape(key) + r'"\s*:\s*)"(?:[^"\\]|\\.)*"')
    if not pattern.search(text):
        raise ListingError('json key not found: ' + key)
    encoded = json.dumps(value, ensure_ascii=False)
    return pattern.sub(lambda m: m.group(1) + encoded, text, count=1)


def set_json_array(text, key, values):
    pattern = re.compile(r'("' + re.escape(key) + r'"\s*:\s*)\[([^\]]*)\]')
    match = pattern.search(text)
    if not match:
        raise ListingError('json array not found: ' + key)
    if '\n' in match.group(2):
        line_start = text.rfind('\n', 0, match.start()) + 1
        indent = re.match(r'\s*', text[line_start:]).group(0)
        inner = ',\n'.join(indent + '  ' + json.dumps(v) for v in values)
        body = '[\n' + inner + '\n' + indent + ']'
    else:
        body = '[' + ', '.join(json.dumps(v) for v in values) + ']'
    return text[:match.start(2) - 1] + body + text[match.end():]


def sub_once(pattern, replacement, text, flags=0):
    result, count = re.subn(pattern, lambda m: replacement(m) if callable(replacement) else replacement, text, count=1, flags=flags)
    if count != 1:
        raise ListingError('pattern not found: ' + pattern)
    return result


def faq_readme(items):
    return '\n\n'.join('= %s =\n\n%s' % (i['q'], i['a']) for i in items)


def render_readme(listing, text):
    title = field(listing, 'wordpress', 'title')
    text = sub_once(r'^=== .+ ===$', '=== %s ===' % title, text, re.M)
    text = sub_once(r'^Tags: .*$', 'Tags: ' + ', '.join(field(listing, 'wordpress', 'tags')), text, re.M)
    text = sub_once(r'(?<=\n\n)[^\n=][^\n]*(?=\n\n== Description ==)', field(listing, 'wordpress', 'short'), text)
    long_text = '\n\n'.join(field(listing, 'wordpress', 'long'))
    bullets = '\n'.join('* ' + f for f in field(listing, 'wordpress', 'features'))
    description_body = '%s\n\nFeatures:\n\n%s\n\n' % (long_text, bullets)
    text = sub_once(r'(== Description ==\n\n).*?(?== External service =)', lambda m: m.group(1) + description_body, text, re.S)
    faq = faq_readme(field(listing, 'wordpress', 'faq'))
    text = sub_once(r'(== Frequently Asked Questions ==\n\n).*?(?=\n== Changelog ==)', lambda m: m.group(1) + faq + '\n', text, re.S)
    changelog = '\n\n'.join('= %s =\n%s' % (c['version'], '\n'.join('* ' + n for n in c['notes'])) for c in listing['product']['changelog'])
    upgrade = '\n\n'.join('= %s =\n%s' % (c['version'], ' '.join(c['notes'])) for c in listing['product']['changelog'])
    text = sub_once(r'== Changelog ==.*\Z', '== Changelog ==\n\n%s\n\n== Upgrade Notice ==\n\n%s\n' % (changelog, upgrade), text, re.S)
    return text


def render_plugin_header(listing, text):
    text = sub_once(r'^ \* Plugin Name: .*$', ' * Plugin Name: ' + field(listing, 'wordpress', 'title'), text, re.M)
    return sub_once(r'^ \* Description: .*$', ' * Description: ' + field(listing, 'wordpress', 'short'), text, re.M)


def render_guide(listing, text):
    text = sub_once(r'^# .+ user guide$', '# %s user guide' % field(listing, 'magento', 'title'), text, re.M)
    body = '\n\n'.join(field(listing, 'magento', 'long'))
    return sub_once(r'(## What it does\n\n).*?(?=\n## )', lambda m: m.group(1) + body + '\n', text, re.S)


def render_prestashop(listing, text):
    text = sub_once(r"(\$this->displayName = \$this->trans\(')[^']*(')", lambda m: m.group(1) + field(listing, 'prestashop', 'title').replace("'", "\\'") + m.group(2), text)
    return sub_once(r"(\$this->description = \$this->trans\(')[^']*(')", lambda m: m.group(1) + field(listing, 'prestashop', 'short').replace("'", "\\'") + m.group(2), text)


def render_all(root):
    listing = load(root)
    validate(listing, root)
    out = {}
    for rel in RENDERED:
        out[rel] = read(root, rel)
    out[WP_README] = render_readme(listing, out[WP_README])
    out[WP_PLUGIN] = render_plugin_header(listing, out[WP_PLUGIN])
    out[WP_COMPOSER] = set_json_string(out[WP_COMPOSER], 'description', description(listing, 'woocommerce_composer'))
    out[MG_COMPOSER] = set_json_string(out[MG_COMPOSER], 'description', description(listing, 'magento'))
    out[MG_GUIDE] = render_guide(listing, out[MG_GUIDE])
    out[PS_COMPOSER] = set_json_string(out[PS_COMPOSER], 'description', description(listing, 'prestashop'))
    out[PS_MODULE] = render_prestashop(listing, out[PS_MODULE])
    out[OC_COMPOSER] = set_json_string(out[OC_COMPOSER], 'description', description(listing, 'opencart'))
    install = set_json_string(out[OC_INSTALL], 'name', field(listing, 'opencart', 'title'))
    out[OC_INSTALL] = set_json_string(install, 'description', description(listing, 'opencart'))
    js = set_json_string(out[JS_PACKAGE], 'description', description(listing, 'npm_js'))
    out[JS_PACKAGE] = set_json_array(js, 'keywords', field(listing, 'npm_js', 'tags'))
    mcp = set_json_string(out[MCP_PACKAGE], 'description', description(listing, 'npm_mcp'))
    out[MCP_PACKAGE] = set_json_array(mcp, 'keywords', field(listing, 'npm_mcp', 'tags'))
    server = set_json_string(out[MCP_SERVER], 'title', field(listing, 'mcp_registry', 'title'))
    out[MCP_SERVER] = set_json_string(server, 'description', description(listing, 'mcp_registry'))
    for rel in (WP_COMPOSER, MG_COMPOSER, PS_COMPOSER, OC_COMPOSER, OC_INSTALL, JS_PACKAGE, MCP_PACKAGE, MCP_SERVER):
        json.loads(out[rel])
    return out


def sheet(listing, platform, label):
    product = listing['product']
    lines = ['# %s listing sheet' % label, '', 'Generated from `integrations/listing.json`. Paste each field into the store form.', '']
    lines += ['## Title', '', field(listing, platform, 'title'), '']
    lines += ['## Tagline', '', field(listing, platform, 'tagline'), '']
    short = field(listing, platform, 'short')
    lines += ['## Short description (%d characters)' % len(short), '', short, '']
    lines += ['## Long description', '', '\n\n'.join(field(listing, platform, 'long')), '']
    lines += ['## Features', ''] + ['- ' + f for f in field(listing, platform, 'features')] + ['']
    lines += ['## Tags', '', ', '.join(field(listing, platform, 'tags')), '']
    lines += ['## FAQ', '']
    for item in field(listing, platform, 'faq'):
        lines += ['**%s**' % item['q'], '', item['a'], '']
    lines += ['## Changelog and release notes', '']
    for entry in product['changelog']:
        lines += ['Version %s' % entry['version']] + ['- ' + n for n in entry['notes']] + ['']
    lines += ['## Support and links', '', '- Support email: ' + product['support_email'], '- Support URL: ' + product['support_url'],
              '- Documentation: ' + product['docs_url'], '- Website: ' + product['website_url'], '']
    return '\n'.join(lines)


def sheets(root):
    listing = load(root)
    return {'%s-listing.md' % platform.replace('_', '-'): sheet(listing, platform, label) for platform, label in SHEETS}


def drift(root):
    rendered = render_all(root)
    return [rel for rel in RENDERED if read(root, rel) != rendered[rel]]


def main(argv):
    mode = argv[1] if len(argv) > 1 else '--check'
    root = ROOT
    if mode == '--check':
        changed = drift(root)
        if changed:
            for rel in changed:
                print('listing drift: ' + rel + ' differs from integrations/listing.json, run make integrations-package', file=sys.stderr)
            return 1
        print('listing check passed: %d files match integrations/listing.json' % len(RENDERED))
        return 0
    if mode == '--write':
        rendered = render_all(root)
        for rel in RENDERED:
            if read(root, rel) != rendered[rel]:
                with open(os.path.join(root, rel), 'w', encoding='utf-8') as handle:
                    handle.write(rendered[rel])
                print('rendered ' + rel)
        return 0
    if mode == '--sheets':
        target = argv[2]
        os.makedirs(target, exist_ok=True)
        for name, content in sheets(root).items():
            with open(os.path.join(target, name), 'w', encoding='utf-8') as handle:
                handle.write(content)
        return 0
    print('usage: render.py --check | --write | --sheets <dir>', file=sys.stderr)
    return 2


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv))
    except ListingError as error:
        print('listing error: ' + str(error), file=sys.stderr)
        sys.exit(1)
