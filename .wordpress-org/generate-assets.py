# -*- coding: utf-8 -*-
from PIL import Image, ImageDraw, ImageFont
import os

OUT = '.wordpress-org'
os.makedirs(OUT, exist_ok=True)
SS = 4  # supersample for smooth shapes

GREEN = (11, 132, 87)     # #0B8457
TEAL  = (16, 176, 150)    # #10B096
DARK  = (8, 92, 62)
WHITE = (255, 255, 255)
INK   = (23, 43, 37)
RED   = (214, 38, 18)     # BG flag red

FB = '/System/Library/Fonts/Supplemental/Arial Bold.ttf'
FR = '/System/Library/Fonts/Supplemental/Arial.ttf'
def font(sz, bold=True): return ImageFont.truetype(FB if bold else FR, sz)

def lerp(a, b, t): return tuple(int(a[i] + (b[i]-a[i])*t) for i in range(3))

def vgrad(w, h, c1, c2):
    img = Image.new('RGB', (w, h))
    d = ImageDraw.Draw(img)
    for y in range(h):
        d.line([(0, y), (w, y)], fill=lerp(c1, c2, y/max(1, h-1)))
    return img

def draw_parcel(draw, cx, cy, s):
    """A clean front-facing parcel with a subtle 3D top + a teal tape cross. s = half-width."""
    depth = int(s*0.42)
    # top face (parallelogram) - light
    top = [(cx-s, cy-s), (cx-s+depth, cy-s-depth), (cx+s+depth, cy-s-depth), (cx+s, cy-s)]
    draw.polygon(top, fill=(238, 242, 245))
    # right face - medium (depth)
    right = [(cx+s, cy-s), (cx+s+depth, cy-s-depth), (cx+s+depth, cy+s-depth), (cx+s, cy+s)]
    draw.polygon(right, fill=(214, 223, 230))
    # front face - white
    fr = (cx-s, cy-s, cx+s, cy+s)
    draw.rectangle(fr, fill=WHITE)
    # tape - vertical + horizontal on the front
    tw = int(s*0.20)
    draw.rectangle((cx-tw//2, cy-s, cx+tw//2, cy+s), fill=(31, 176, 160))
    draw.rectangle((cx-s, cy-tw//2, cx+s, cy+tw//2), fill=(31, 176, 160))
    # tape on the top face
    draw.polygon([(cx-tw//2, cy-s), (cx-tw//2+depth, cy-s-depth), (cx+tw//2+depth, cy-s-depth), (cx+tw//2, cy-s)], fill=(20, 150, 136))

def draw_pin(draw, cx, cy, r):
    """A location pin (teardrop) - red with a white dot, to signal a delivery point."""
    draw.ellipse((cx-r, cy-r, cx+r, cy+r), fill=RED)
    draw.polygon([(cx-r*0.7, cy+r*0.35), (cx+r*0.7, cy+r*0.35), (cx, cy+r*1.9)], fill=RED)
    draw.ellipse((cx-r*0.42, cy-r*0.42, cx+r*0.42, cy+r*0.42), fill=WHITE)

# ---------- ICON ----------
def make_icon(size):
    base = vgrad(size*SS, size*SS, TEAL, GREEN).convert('RGBA')
    ov = Image.new('RGBA', (size*SS, size*SS), (0,0,0,0))
    d = ImageDraw.Draw(ov)
    c = size*SS//2
    draw_parcel(d, c, int(size*SS*0.56), int(size*SS*0.28))
    draw_pin(d, int(size*SS*0.72), int(size*SS*0.30), int(size*SS*0.11))
    base.alpha_composite(ov)
    # rounded corners
    rad = int(size*SS*0.20)
    mask = Image.new('L', (size*SS, size*SS), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0,0,size*SS-1,size*SS-1), radius=rad, fill=255)
    base.putalpha(mask)
    out = base.resize((size, size), Image.LANCZOS)
    return out

make_icon(256).save(f'{OUT}/icon-256x256.png')
make_icon(128).save(f'{OUT}/icon-128x128.png')

# ---------- BANNER ----------
def make_banner(w, h):
    base = vgrad(w, h, GREEN, TEAL).convert('RGBA')
    # subtle diagonal sheen
    sheen = Image.new('RGBA', (w, h), (0,0,0,0))
    ds = ImageDraw.Draw(sheen)
    ds.polygon([(0,0),(int(w*0.5),0),(int(w*0.28),h),(0,h)], fill=(255,255,255,14))
    base.alpha_composite(sheen)
    scale = h/250.0
    # parcel + pin on the left
    ov = Image.new('RGBA', (w*SS, h*SS), (0,0,0,0))
    d = ImageDraw.Draw(ov)
    pcx = int(w*SS*0.135); pcy = int(h*SS*0.52); ps = int(h*SS*0.24)
    draw_parcel(d, pcx, pcy, ps)
    draw_pin(d, int(pcx+ps*1.15), int(pcy-ps*1.1), int(h*SS*0.085))
    base.alpha_composite(ov.resize((w, h), Image.LANCZOS))
    # text
    d = ImageDraw.Draw(base)
    tx = int(w*0.275)
    d.text((tx, int(h*0.20)), 'BG Couriers', font=font(int(60*scale), True), fill=WHITE)
    d.text((tx, int(h*0.20)+int(64*scale)), 'for WooCommerce', font=font(int(30*scale), False), fill=(226,246,238))
    d.text((tx, int(h*0.20)+int(104*scale)), 'Speedy · Econt · BOX NOW · Pigeon · Sameday',
           font=font(int(20*scale), True), fill=(210,240,232))
    d.text((tx, int(h*0.20)+int(133*scale)), 'Office · address · locker delivery — live rates, labels, tracking',
           font=font(int(17*scale), False), fill=(198,232,224))
    return base.convert('RGB')

make_banner(772, 250).save(f'{OUT}/banner-772x250.png')
make_banner(1544, 500).save(f'{OUT}/banner-1544x500.png')

print('generated:', sorted(os.listdir(OUT)))
