from lxml import etree
from PythonSed import Sed, SedException
import sys
import io
import re
import os
import hashlib

scan_root = sys.argv[1]
content = ''
_PHP_TAG = re.compile(r'<\?php.*?\?>', re.DOTALL)
_BLADE_TAG = re.compile(r'{{.*}}', re.DOTALL)
sed = Sed()

# array with all the seen classes
classes = {}

stats = {
    'ok': 0,
    'error': 0,
    'file_count': 0
}


def hash_style_bloc(s):
    hash = hashlib.sha1(s.encode('utf-8'))
    return hash.hexdigest()


def walk_directory(top_directory):
    for root, _, files in os.walk(top_directory):
        for file in files:
            fp = os.path.join(root, file)
            if file[-10:] == '.blade.php':
                # print(full_file_path)
                parse_file(fp)
                

def strip_php(content):
    return _PHP_TAG.sub('', content)


def format_class(hash, str):
    lines = []
    lines.append('%s: {' % hash)

    for style in str.split(';'):
        style = style.strip()

        if not len(style):
            continue

        lines.append(f'    {style};')

    lines.append('}')

    return '\n'.join(lines)

# separate all k/v in two group
# those that's going to move in external css and those that will stay inline
# (because {{}} call inside key or value)
def split_inline_style(s):
    external = []
    inline = []
    for kv in s.split(';'):
        hash = hash_style_bloc(kv)
        if not re.search(_BLADE_TAG, kv):
            external.append(hash)
            type = 'EXTERNAL'
        else:
            inline.append(hash)
            type = 'INTERNAL'

        if not hash in classes:
            classes[hash] = { 'type': type, 'original_inline': s, 'replacement_bloc': s }

    return (external, inline)


# TODO
# what if there is already a class attribute
# what if there is multiline inline style
def replace_text(fp, external, inline):
    for k in external:
        blade_file = open(fp, 'r').read()
        to_lookup = 'style="%s"' % classes[k]['original_inline']
        print('to lookup:' + to_lookup)
        replacement_hash = k

        hash_before = hash_style_bloc(str(blade_file))
        blade_file_after = blade_file.replace(to_lookup,f'class="{replacement_hash}"')
        hash_after = hash_style_bloc(str(blade_file_after))

        if hash_before != hash_after:
            writer = open(f'{fp}.new', 'w')
            writer.write(blade_file_after)
            stats['ok'] += 1
        else:
            stats['error'] += 1
            # print(f'ERROR: file [{fp}] - [{to_lookup}]')


def parse_file(fp):
    content = ''
    stats['file_count'] += 1
    for line in open(fp, 'r').readlines():
        if len(line.strip()) > 0 and not line.strip()[0] == '@':
            content = content + strip_php(line)

    wrapped_data = f"<temp_root>{content}</temp_root>"

    parser = etree.XMLParser(recover=True)

    try:
        tree = etree.fromstring(wrapped_data, parser)
        error = None
    except Exception as e:
        error = e

    if error:
        print(error)    
        sys.exit(1)
    
    styles = tree.xpath("//@style")
    external = []
    inline = []

    for str in styles:
        external, inline = split_inline_style(str)
        replace_text(fp, external, inline)
      
    # return external, inline

walk_directory(scan_root)

ok = stats['ok']
error = stats['error']
file_count = stats['file_count']

print(f'STATS: #file: {file_count}, ok: {ok}, error: {error} ({round(ok/(ok+error)*100,2)}% success)')