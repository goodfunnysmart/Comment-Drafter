"""Run against local PHP server: python tests/http_check.py [base-url]. No AI call."""
import http.cookiejar, json, re, sys, urllib.request, urllib.error
base = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8765').rstrip('/')
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def request(path, payload=None, headers=None, raw=None, binary=False):
    body = raw if raw is not None else json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(base+path, data=body, headers=headers or {})
    try: response = client.open(req)
    except urllib.error.HTTPError as error: response = error
    payload_bytes = response.read()
    return response.code, response.headers, payload_bytes if binary else payload_bytes.decode()
status, headers, page = request('/')
assert status == 200 and 'no-store' in headers['Cache-Control']
assert "img-src 'self'" in headers['Content-Security-Policy']
assert page.count('alt="Better Schools"') == 2
assert 'assets/logo-classic.webp' in page and 'assets/logo-dark.webp' in page
assert page.count('class="chip"') == 26
assert page.count('>Strengths<') == 4 and page.count('>Areas for growth<') == 4
assert page.count('pair-columns') == 4 and page.count('growth-column') == 4
assert 'Engagement in Learning' in page and 'Next Term Focus' in page and 'Using Feedback' in page and 'Chunking learning tasks' in page
supports = re.search(r'data-category="supports".*?data-category="next-term"', page, re.S).group(0)
assert 'pair-columns' not in supports and supports.count('class="chip"') == 5
engagement = re.search(r'data-category="engagement".*?data-category="social"', page, re.S).group(0)
assert engagement.index('>Strengths<') < engagement.index('>Areas for growth<')
assert 'id="tweak"' in page and 'What would you like changed?' in page and 'Tweak comment' in page and 'id="undo-tweak"' in page
assert 'Negative behaviours to reframe' in page and 'id="reframe"' in page and 'never named in the comment' in page
assert 'id="arrangements"' not in page and 'Reporting arrangements' not in page
for logo in ('/assets/logo-classic.webp', '/assets/logo-dark.webp'):
    logo_status, logo_headers, logo_body = request(logo, binary=True)
    assert logo_status == 200 and logo_body[:4] == b'RIFF' and logo_body[8:12] == b'WEBP', (logo, logo_status, logo_headers.get('Content-Type'))
    assert 'image/webp' in logo_headers.get('Content-Type', ''), logo_headers.get('Content-Type')
assert 'HttpOnly' in headers['Set-Cookie'] and 'SameSite=Strict' in headers['Set-Cookie']
token = re.search(r'name="csrf-token" content="([a-f0-9]+)"', page)[1]
valid = dict(name='Alex', pronouns='they', subject='', observations='', length='standard', strengths=['natural-curiosity'], nextSteps=[])
good_headers = {'Content-Type':'application/json', 'X-CSRF-Token':token, 'Origin':base}
assert request('/generate.php')[0] == 405
assert request('/generate.php', valid, {'Content-Type':'application/json'})[0] == 403
assert request('/generate.php', valid, dict(good_headers, Origin='https://another.example'))[0] == 403
assert request('/generate.php', valid, dict(good_headers, **{'Sec-Fetch-Site':'cross-site'}))[0] == 403
assert request('/generate.php', dict(valid, name=' '), good_headers)[0] == 422
assert request('/generate.php', dict(valid, strengths=[]), good_headers)[0] == 422
assert request('/generate.php', dict(valid, strengths=['invented']), good_headers)[0] == 422
assert request('/generate.php', dict(valid, supports=['not-a-support']), good_headers)[0] == 422
assert request('/generate.php', dict(valid, reframe='x' * 501), good_headers)[0] == 422
assert request('/generate.php', dict(valid, mode='tweak', comment='Alex is kind.', tweak=' '), good_headers)[0] == 422
assert request('/generate.php', dict(valid, mode='tweak', comment='Alex is kind.', tweak='x' * 501), good_headers)[0] == 422
assert request('/generate.php', dict(valid, mode='rewrite'), good_headers)[0] == 422
assert request('/generate.php', headers=good_headers, raw=b'{broken')[0] == 422
assert request('/generate.php', headers=good_headers, raw=b' ' * 24001)[0] == 413
assert request('/private/config.php')[0] == 404
assert request('/private/bank.json')[0] == 404
assert request('/private/instructions.txt')[0] == 404
assert request('/private/bootstrap.php')[0] == 404
assert request('/bank.json')[0] == 404
assert request('/README.md')[0] == 404
status, headers, body = request('/private_path.php')
assert status == 404 and 'comments-private' not in body and 'bootstrap.php' not in body
status, headers, body = request('/generate.php', dict(valid, reframe='Rushes work'), good_headers)
assert status == 503 and json.loads(body)['ok'] is False, (status, body)
assert 'no-store' in headers['Cache-Control']
assert request('/generate.php', valid, good_headers)[0] == 429
print('PASS: rendering, 26 chips, two columns, tweak field, security headers/cookie, POST/CSRF/origin, invalid data, size cap, private paths, unconfigured error, throttle. No live AI call.')
