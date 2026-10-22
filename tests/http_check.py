"""Run against local PHP server: python tests/http_check.py [base-url]. No AI call."""
import http.cookiejar, json, re, sys, urllib.request, urllib.error
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo
base = (sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8765').rstrip('/')
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def request(path, payload=None, headers=None, raw=None, binary=False):
    body = raw if raw is not None else json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(base+path, data=body, headers=headers or {})
    try: response = client.open(req, timeout=20)
    except urllib.error.HTTPError as error: response = error
    payload_bytes = response.read()
    return response.code, response.headers, payload_bytes if binary else payload_bytes.decode()
status, headers, page = request('/')
assert status == 200 and 'no-store' in headers['Cache-Control']
assert "img-src 'self'" in headers['Content-Security-Policy']
assert page.count('alt="Better Schools"') == 2
assert 'assets/logo-classic.webp' in page and 'assets/logo-dark.webp' in page
assert page.count('class="chip"') == 40
assert page.count('>Strengths<') == 4 and page.count('>Areas for Growth<') == 4 and page.count('>Challenges and Obstacles<') == 4
assert page.count('traffic-columns') == 4 and page.count('growth-column') == 4 and page.count('challenge-column') == 4
assert 'Engagement in Learning' in page and 'Next Term Focus' in page and 'Using Feedback' in page and 'Learning support' in page and 'Movement breaks' in page
assert 'Other specific adjustments' in page and 'id="adjustments"' in page and 'maxlength="200"' in page
order = ['data-category="engagement"', 'data-category="social"', 'data-category="attendance"', 'data-category="next-term"', 'data-category="supports"']
assert [page.index(marker) for marker in order] == sorted(page.index(marker) for marker in order)
supports = re.search(r'data-category="supports".*?(?=</section>\s*<section)', page, re.S).group(0)
assert 'traffic-columns' not in supports and supports.count('class="chip"') == 11 and 'id="adjustments"' in supports
assert len(re.findall(r'class="chip"[^>]*\baria-describedby=', page)) == 0
assert len(re.findall(r'class="info"[^>]*\baria-describedby="[^"]+"', page)) == 40
assert not re.search(r'<(?:button|label)\b[^>]*\btitle=', page)
engagement = re.search(r'data-category="engagement".*?data-category="social"', page, re.S).group(0)
assert engagement.index('>Strengths<') < engagement.index('>Areas for Growth<') < engagement.index('>Challenges and Obstacles<')
assert 'id="tweak"' in page and 'What would you like changed?' in page and 'Tweak comment' in page and 'id="undo-tweak"' in page
assert 'id="session-toggle"' in page and 'id="commit-session"' in page and 'Commit to session' in page
assert 'Use a first name only. Do not include a school, campus, class, or other identifying details.' in page
assert 'Write this down. Without this code the comments cannot be opened.' in page
assert 'Email me this code' in page and 'It is not stored.' in page
assert 'Start new session' in page and 'Leave session' in page and 'Clear session' in page
assert 'Download .txt' in page and 'Download .docx' in page
assert 'Negative behaviours to reframe' in page and 'id="reframe"' in page and 'never named in the comment' in page
assert 'Dictation sends that recording to the same provider and does not include the name.' in page and 'This app does not store the audio.' in page
assert 'Student names are never sent to the AI. Please avoid typing other identifying details.' in page
mic_buttons = re.findall(r'<button type="button" class="dictate-mic" hidden[^>]*>', page)
assert len(mic_buttons) == 5, len(mic_buttons)
for label in ('Dictate teacher observations', 'Dictate negative behaviours to reframe', 'Dictate other specific adjustments', 'Dictate clubs or activities', 'Dictate the change you want'):
    assert f'aria-label="{label}"' in page, label
assert 'Dictate the comment' not in page and 'comment-dictate-status' not in page
assert 'id="arrangements"' not in page and 'Reporting arrangements' not in page
assert 'id="subject"' not in page and 'Subject or learning area' not in page
assert 'Short · approximately 60–90 words' in page and 'value="standard" selected>Standard · approximately 90–120 words' in page
assert 'Detailed · approximately 120–150 words' in page and 'Long · approximately 150–200 words' in page
for logo in ('/assets/logo-classic.webp', '/assets/logo-dark.webp'):
    logo_status, logo_headers, logo_body = request(logo, binary=True)
    assert logo_status == 200 and logo_body[:4] == b'RIFF' and logo_body[8:12] == b'WEBP', (logo, logo_status, logo_headers.get('Content-Type'))
    assert 'image/webp' in logo_headers.get('Content-Type', ''), logo_headers.get('Content-Type')
assert 'HttpOnly' in headers['Set-Cookie'] and 'SameSite=Strict' in headers['Set-Cookie']
token = re.search(r'name="csrf-token" content="([a-f0-9]+)"', page)[1]
valid = dict(name='Alex', pronouns='they', observations='', length='standard', strengths=['natural-curiosity'], nextSteps=[])
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
assert request('/generate.php', dict(valid, length='huge'), good_headers)[0] == 422
assert request('/generate.php', dict(valid, adjustments='x' * 201), good_headers)[0] == 422
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
now = datetime.now(ZoneInfo('Australia/Sydney'))
expiry_month = now.month + 2
expiry_year = now.year + (expiry_month - 1) // 12
expiry_month = (expiry_month - 1) % 12 + 1
expected_expiry = f'{expiry_year:04d}-{expiry_month:02d}-01'
throttle_dir = Path(__file__).resolve().parents[1] / 'private' / 'sessions' / 'throttle'
if throttle_dir.is_dir():
    for leftover in throttle_dir.glob('*.json'): leftover.unlink()
assert request('/session.php')[0] == 405
assert request('/session.php', {'action':'create'}, {'Content-Type':'application/json'})[0] == 403
assert request('/session.php', {'action':'create'}, dict(good_headers, Origin='https://another.example'))[0] == 403
assert request('/session.php', {'action':'create'}, dict(good_headers, **{'Sec-Fetch-Site':'cross-site'}))[0] == 403
assert request('/sessions.php')[0] == 404
assert request('/private/sessions.php')[0] == 404
assert request('/cleanup-sessions.php')[0] == 404
status, headers, body = request('/session.php', {'action':'create'}, good_headers)
assert status == 200, (status, body)
created = json.loads(body)
assert created['ok'] is True and created['count'] == 0 and created['comments'] == []
assert re.fullmatch(r'[A-Z]{4}-[2-9]{4}-[A-Z]{4}-[A-Z]{4}', created['code'])
assert not re.search(r'[ILO01]', created['code'])
assert created['expires'] == expected_expiry
assert created['code'] not in page
code = created['code']
status, headers, body = request('/session.php', {'action':'resume', 'code': code.lower().replace('-', ' ')}, good_headers)
assert status == 200 and json.loads(body)['code'] == code
status, headers, body = request('/session.php', {'action':'resume', 'code':'nope'}, good_headers)
assert status == 422
comments = [{'id':'abcd1234','name':'alex','text':'Kept a steady pace.'}, {'id':'efgh5678','name':'Sam','text':'Asked for the next step.'}]
status, headers, body = request('/session.php', {'action':'save','code':code,'comments':comments}, good_headers)
saved = json.loads(body)
assert status == 200 and saved['count'] == 2 and saved['comments'][0]['name'] == 'Alex', (status, body)
status, headers, body = request('/session.php', {'action':'save','code':code,'comments':list(reversed(saved['comments']))}, good_headers)
assert status == 200 and json.loads(body)['comments'][0]['name'] == 'Sam'
status, headers, body = request('/session.php', {'action':'download','code':code,'format':'txt'}, good_headers)
assert status == 200 and body.startswith('Sam\n\nAsked for the next step.') and 'Alex' in body and code not in body
assert 'text/plain' in headers.get('Content-Type', '') and 'session-comments.txt' in headers.get('Content-Disposition', '')
status, headers, raw = request('/session.php', {'action':'download','code':code,'format':'docx'}, good_headers, binary=True)
assert status == 200 and raw[:4] == b'PK\x03\x04' and code.encode() not in raw
assert 'session-comments.docx' in headers.get('Content-Disposition', '')
for _ in range(8):
    missed = request('/session.php', {'action':'resume','code':'ZYZY-9876-ZYZY-ZYZY'}, good_headers)
    assert missed[0] in (404, 429), missed[0]
assert request('/session.php', {'action':'resume','code':'ZYZY-9876-ZYZY-WYWY'}, good_headers)[0] == 429
status, headers, body = request('/session.php', {'action':'resume','code':code}, good_headers)
assert status == 200 and json.loads(body)['code'] == code, (status, body)
assert request('/session.php', {'action':'email','code':code,'email':'not-an-email'}, good_headers)[0] == 422
status, headers, body = request('/session.php', {'action':'email','code':code,'email':'teacher@example.com'}, good_headers)
assert status in (200, 502), (status, body)
assert 'teacher@example.com' not in body and code not in body
status, headers, body = request('/session.php', {'action':'clear','code':code}, good_headers)
assert status == 200 and json.loads(body)['cleared'] is True
status, headers, body = request('/session.php', {'action':'resume','code':code}, good_headers)
assert status == 404 and 'not found' in body.lower()
for _ in range(6):
    missed = request('/session.php', {'action':'resume','code':'ZYZY-9876-ZYZY-ZYZY'}, good_headers)
    assert missed[0] == 404, missed[0]
blocked = request('/session.php', {'action':'resume','code':'ZYZY-9876-ZYZY-WYWY'}, good_headers)
assert blocked[0] == 429, blocked[0]
def transcribe(payload, headers=None, raw=None):
    return request('/transcribe.php', payload, headers if headers is not None else good_headers, raw)
assert transcribe(None, {})[0] == 405
assert transcribe({'audio': 'YQ==', 'mime': 'audio/webm'}, {'Content-Type': 'application/json'})[0] == 403
assert transcribe({'audio': 'YQ==', 'mime': 'audio/webm'}, dict(good_headers, Origin='https://another.example'))[0] == 403
assert transcribe({'audio': 'YQ==', 'mime': 'audio/webm'}, dict(good_headers, **{'Sec-Fetch-Site': 'cross-site'}))[0] == 403
assert transcribe({'audio': 'YQ==', 'mime': 'application/json'})[0] == 415
assert transcribe({'audio': '!!!', 'mime': 'audio/webm'})[0] == 422
assert transcribe({'audio': '', 'mime': 'audio/webm'})[0] == 422
assert transcribe({'audio': 'YQ==', 'mime': 'audio/webm'})[0] == 422
short = __import__('base64').b64encode(b'x' * 40).decode()
assert transcribe({'audio': short, 'mime': 'audio/webm;codecs=opus'})[0] == 422
over_body = request('/transcribe.php', headers=good_headers, raw=b'{"mime":"audio/webm","audio":"' + (b'A' * 7000001) + b'"}')
assert over_body[0] == 413, over_body[0]
over_audio = __import__('base64').b64encode(b'\x00' * 5000001).decode()
assert transcribe({'audio': over_audio, 'mime': 'audio/mp4'})[0] == 413
clip = __import__('base64').b64encode(b'\x1a\x45\xdf\xa3' + (b'\x00' * 120)).decode()
status, headers, body = transcribe({'audio': clip, 'mime': 'audio/webm;codecs=opus'})
parsed = json.loads(body)
assert status == 503 and parsed['ok'] is False and clip not in body, (status, body)
assert 'not configured' in parsed['error'].lower()
status, headers, body = transcribe({'audio': clip, 'mime': 'audio/mp4'})
assert status == 429 and 'duration_ms' not in json.loads(body), (status, body)
status, headers, body = request('/generate.php', dict(valid, reframe='Rushes work', length='long'), good_headers)
assert status == 503 and json.loads(body)['ok'] is False, (status, body)
assert 'no-store' in headers['Cache-Control']
assert request('/generate.php', valid, good_headers)[0] == 429
print('PASS: rendering, 40 chips, three columns, adjustments field, tweak field, dictation controls, session panel, security headers/cookie, POST/CSRF/origin, invalid data, size cap, private paths, session storage, unconfigured error, separate dictation throttle. No live AI call.')
