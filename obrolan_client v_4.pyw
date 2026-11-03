import getpass
import json
import math
import socket
import struct
import sys
import tempfile
import threading
import uuid
import wave
from datetime import datetime, date, timedelta
from pathlib import Path

import requests
import wx
import wx.adv

# GANTI xx sesuai IP server Docker kamu
SERVER = "http://192.168.60.228:8070/api.php"
POLL_MS = 2000
LOCK_PORT = 47653  # port lokal untuk deteksi "aplikasi sudah terbuka"
CFG_PATH = Path.home() / ".obrolan_config.json"


# ====================== config (cache nama, popup, suara) ======================
def load_config():
    cfg = {"name": getpass.getuser() or "python", "popup": True, "sound": True}
    try:
        cfg.update(json.loads(CFG_PATH.read_text(encoding="utf-8")))
    except Exception:
        pass
    return cfg


def save_config(cfg):
    try:
        CFG_PATH.write_text(json.dumps(cfg, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass


def load_cid():
    """cid disimpan di file lokal supaya setelah aplikasi ditutup,
    pesan lama tetap dikenali sebagai 'punya saya' (bubble kanan, biru)."""
    p = Path.home() / ".obrolan_cid"
    try:
        c = p.read_text().strip()
        if c:
            return c
    except Exception:
        pass
    c = uuid.uuid4().hex[:24]
    try:
        p.write_text(c)
    except Exception:
        pass
    return c


CID = load_cid()


# ====================== ikon logo (digambar lewat kode) ======================
BLUE = (24, 119, 242)
WHITE = (255, 255, 255)
RED = (230, 50, 50)


def _in_rrect(u, v, x0, y0, x1, y1, r):
    cx = min(max(u, x0 + r), x1 - r)
    cy = min(max(v, y0 + r), y1 - r)
    return (u - cx) ** 2 + (v - cy) ** 2 <= r * r


def _in_tri(u, v, a, b, c):
    def s(p, q, r):
        return (p[0] - r[0]) * (q[1] - r[1]) - (q[0] - r[0]) * (p[1] - r[1])
    p = (u, v)
    d1, d2, d3 = s(p, a, b), s(p, b, c), s(p, c, a)
    return not ((d1 < 0 or d2 < 0 or d3 < 0) and (d1 > 0 or d2 > 0 or d3 > 0))


def _sample(u, v, alert):
    if alert:  # titik merah penanda pesan baru
        d = (u - .76) ** 2 + (v - .24) ** 2
        if d <= .21 ** 2:
            return RED
        if d <= .27 ** 2:
            return WHITE
    if (u - .5) ** 2 + (v - .5) ** 2 > .25:
        return None
    if _in_rrect(u, v, .17, .24, .83, .62, .13) or _in_tri(u, v, (.28, .58), (.28, .82), (.50, .60)):
        for cx in (.34, .5, .66):
            if (u - cx) ** 2 + (v - .43) ** 2 <= .055 ** 2:
                return BLUE
        return WHITE
    return BLUE


def make_icon_rgba(size, alert=False):
    n = 4  # supersampling biar halus
    rgb, alpha = bytearray(), bytearray()
    for py in range(size):
        for px in range(size):
            r = g = bl = cnt = 0
            for sy in range(n):
                for sx in range(n):
                    c = _sample((px + (sx + .5) / n) / size, (py + (sy + .5) / n) / size, alert)
                    if c:
                        r += c[0]; g += c[1]; bl += c[2]; cnt += 1
            if cnt:
                rgb += bytes((r // cnt, g // cnt, bl // cnt))
                alpha.append(cnt * 255 // (n * n))
            else:
                rgb += b"\x00\x00\x00"
                alpha.append(0)
    return bytes(rgb), bytes(alpha)


def make_icon(size, alert=False):
    rgb, alpha = make_icon_rgba(size, alert)
    img = wx.Image(size, size)
    img.SetData(rgb)
    img.SetAlpha(alpha)
    icon = wx.Icon()
    icon.CopyFromBitmap(wx.Bitmap(img))
    return icon


# ====================== suara "tring" ======================
def make_tring_wav(path):
    rate = 44100
    total = int(rate * 0.8)
    buf = [0.0] * total

    def add(freq, start, dur, vol):
        s0 = int(start * rate)
        for i in range(int(dur * rate)):
            if s0 + i >= total:
                break
            t = i / rate
            env = math.exp(-t * 6.5) * min(1.0, t * 300)
            v = (math.sin(2 * math.pi * freq * t)
                 + .35 * math.sin(2 * math.pi * freq * 2.01 * t)
                 + .15 * math.sin(2 * math.pi * freq * 3.02 * t))
            buf[s0 + i] += env * v * vol

    add(1318.5, 0.00, .50, 1.0)
    add(1760.0, 0.13, .65, 1.0)
    peak = max(abs(x) for x in buf) or 1
    with wave.open(str(path), "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(b"".join(struct.pack("<h", int(x / peak * 0.7 * 32767)) for x in buf))


def ensure_sound():
    p = Path(tempfile.gettempdir()) / "obrolan_tring.wav"
    if not p.exists():
        try:
            make_tring_wav(p)
        except Exception:
            pass
    return p


_snd_ref = None


def play_sound(path):
    global _snd_ref
    try:
        if sys.platform.startswith("win"):
            import winsound
            winsound.PlaySound(str(path), winsound.SND_FILENAME | winsound.SND_ASYNC)
        else:
            _snd_ref = wx.adv.Sound(str(path))
            _snd_ref.Play(wx.adv.SOUND_ASYNC)
    except Exception:
        pass


# ====================== satu instance saja ======================
def acquire_lock():
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        s.bind(("127.0.0.1", LOCK_PORT))
        s.listen(5)
        return s
    except OSError:
        s.close()
        return None


def signal_existing():
    """Minta instance yang sudah jalan untuk tampil. True kalau berhasil."""
    try:
        if sys.platform.startswith("win"):
            import ctypes
            ctypes.windll.user32.AllowSetForegroundWindow(-1)  # izinkan jendela lama maju ke depan
    except Exception:
        pass
    try:
        with socket.create_connection(("127.0.0.1", LOCK_PORT), timeout=1) as c:
            c.sendall(b"SHOW")
        return True
    except OSError:
        return False


def listen_for_show(srv, callback):
    while True:
        try:
            conn, _ = srv.accept()
        except OSError:
            break
        try:
            conn.settimeout(1)
            if conn.recv(16).startswith(b"SHOW"):
                wx.CallAfter(callback)
        except Exception:
            pass
        finally:
            conn.close()


class ChatView(wx.ScrolledWindow):
    PAD = 12          # jarak ke tepi jendela
    BPX = 12          # padding horizontal dalam bubble
    BPY = 7           # padding vertikal dalam bubble
    GAP = 3           # jarak antar pesan dari orang yang sama
    GROUP_GAP = 10    # jarak antar pesan beda pengirim
    RADIUS = 14

    C_BG = wx.Colour(236, 239, 243)
    C_ME = wx.Colour(24, 119, 242)
    C_OTHER = wx.Colour(255, 255, 255)
    C_BORDER = wx.Colour(221, 225, 230)
    NAME_COLORS = [
        wx.Colour(200, 60, 60), wx.Colour(40, 150, 90), wx.Colour(180, 110, 20),
        wx.Colour(130, 70, 180), wx.Colour(20, 140, 160), wx.Colour(190, 70, 130),
    ]

    def __init__(self, parent):
        super().__init__(parent, style=wx.VSCROLL)
        self.SetBackgroundStyle(wx.BG_STYLE_PAINT)
        self.SetBackgroundColour(self.C_BG)
        self.SetScrollRate(0, 8)
        if wx.Platform == "__WXMSW__":
            self.SetDoubleBuffered(True)

        self.f_msg = wx.Font(wx.FontInfo(10))
        self.f_name = wx.Font(wx.FontInfo(9).Bold())
        self.f_small = wx.Font(wx.FontInfo(8))

        self.messages = []
        self.my_cid = CID
        self.items = []
        self.total_h = 0

        self.Bind(wx.EVT_PAINT, self.on_paint)
        self.Bind(wx.EVT_SIZE, self.on_size)

    # ---------- API publik ----------
    def set_messages(self, msgs, my_cid, force_bottom=False):
        at_bottom = self._at_bottom()
        self.messages = msgs
        self.my_cid = my_cid
        self._layout()
        if at_bottom or force_bottom:
            self._scroll_bottom()
        self.Refresh()

    # ---------- util ----------
    def _at_bottom(self):
        ch = self.GetClientSize().height
        oy = self.GetViewStart()[1] * self.GetScrollPixelsPerUnit()[1]
        return oy + ch >= self.total_h - 20

    def _scroll_bottom(self):
        ch = self.GetClientSize().height
        uy = self.GetScrollPixelsPerUnit()[1] or 1
        self.Scroll(0, max(0, (self.total_h - ch + uy - 1) // uy))

    @staticmethod
    def _day_label(d):
        today = date.today()
        if d == today:
            return "Hari ini"
        if d == today - timedelta(days=1):
            return "Kemarin"
        return d.strftime("%d/%m/%Y")

    def _name_color(self, name):
        return self.NAME_COLORS[sum(map(ord, name)) % len(self.NAME_COLORS)]

    @staticmethod
    def _ext(gc, s):
        return gc.GetTextExtent(s)[:2]

    def _wrap(self, gc, text, maxw):
        lines = []
        for para in text.split("\n"):
            cur = ""
            for word in para.split(" "):
                cand = word if not cur else cur + " " + word
                if self._ext(gc, cand)[0] <= maxw:
                    cur = cand
                    continue
                if cur:
                    lines.append(cur)
                    cur = ""
                while self._ext(gc, word)[0] > maxw and len(word) > 1:
                    n = len(word)
                    while n > 1 and self._ext(gc, word[:n])[0] > maxw:
                        n -= 1
                    lines.append(word[:n])
                    word = word[n:]
                cur = word
            lines.append(cur)
        return lines

    # ---------- layout ----------
    def _layout(self):
        cw = max(self.GetClientSize().width, 120)
        gc = wx.GraphicsContext.Create(self)
        self.items = []
        y = self.PAD
        max_bw = int(cw * 0.72)
        prev_sender = None
        prev_day = None

        for m in self.messages:
            try:
                dt = datetime.fromtimestamp(int(m.get("time", 0)))
            except Exception:
                dt = datetime.fromtimestamp(0)
            name = str(m.get("name", "?"))
            text = str(m.get("message", ""))
            cid = str(m.get("cid", ""))
            mine = bool(cid) and cid == self.my_cid
            sender = cid or name

            # pemisah tanggal
            if dt.date() != prev_day:
                gc.SetFont(self.f_small, wx.BLACK)
                label = self._day_label(dt.date())
                tw, th = self._ext(gc, label)
                pw, ph = int(tw) + 18, int(th) + 8
                self.items.append(dict(kind="date", label=label, x=(cw - pw) // 2,
                                       y=y, w=pw, h=ph, th=th, tw=tw))
                y += ph + self.GROUP_GAP
                prev_day = dt.date()
                prev_sender = None
            elif prev_sender is not None:
                y += self.GAP if sender == prev_sender else self.GROUP_GAP

            show_name = (not mine) and sender != prev_sender

            gc.SetFont(self.f_msg, wx.BLACK)
            lines = self._wrap(gc, text, max_bw - 2 * self.BPX)
            lh = self._ext(gc, "Ag")[1]
            tw = max(self._ext(gc, ln)[0] for ln in lines)

            nh = nw = 0
            if show_name:
                gc.SetFont(self.f_name, wx.BLACK)
                nw, nh = self._ext(gc, name)

            tstr = dt.strftime("%H:%M")
            gc.SetFont(self.f_small, wx.BLACK)
            timew, timeh = self._ext(gc, tstr)

            bw = int(max(tw, nw, timew)) + 2 * self.BPX
            bh = int(2 * self.BPY + (nh + 2 if show_name else 0)
                     + len(lines) * lh + 2 + timeh)
            x = cw - self.PAD - bw if mine else self.PAD

            self.items.append(dict(
                kind="msg", mine=mine, name=name, show_name=show_name,
                lines=lines, lh=lh, nh=nh, tstr=tstr, timew=timew,
                x=x, y=y, w=bw, h=bh))
            y += bh
            prev_sender = sender

        self.total_h = y + self.PAD
        self.SetVirtualSize((cw, self.total_h))

    # ---------- event ----------
    def on_size(self, evt):
        at_bottom = self._at_bottom()
        self._layout()
        if at_bottom:
            self._scroll_bottom()
        self.Refresh()
        evt.Skip()

    def on_paint(self, _evt):
        dc = wx.PaintDC(self)
        gc = wx.GraphicsContext.Create(dc)
        cw, ch = self.GetClientSize()
        oy = self.GetViewStart()[1] * self.GetScrollPixelsPerUnit()[1]

        gc.SetPen(wx.TRANSPARENT_PEN)
        gc.SetBrush(wx.Brush(self.C_BG))
        gc.DrawRectangle(0, 0, cw, ch)

        for it in self.items:
            if it["y"] + it["h"] < oy or it["y"] > oy + ch:
                continue
            y = it["y"] - oy
            x = it["x"]

            if it["kind"] == "date":
                gc.SetPen(wx.TRANSPARENT_PEN)
                gc.SetBrush(wx.Brush(wx.Colour(214, 219, 226)))
                gc.DrawRoundedRectangle(x, y, it["w"], it["h"], it["h"] / 2)
                gc.SetFont(self.f_small, wx.Colour(80, 86, 95))
                gc.DrawText(it["label"], x + (it["w"] - it["tw"]) / 2, y + 4)
                continue

            # bubble
            if it["mine"]:
                gc.SetPen(wx.TRANSPARENT_PEN)
                gc.SetBrush(wx.Brush(self.C_ME))
                fg = wx.WHITE
                tcol = wx.Colour(205, 226, 255)
            else:
                gc.SetPen(wx.Pen(self.C_BORDER, 1))
                gc.SetBrush(wx.Brush(self.C_OTHER))
                fg = wx.Colour(30, 33, 38)
                tcol = wx.Colour(140, 146, 155)
            gc.DrawRoundedRectangle(x, y, it["w"], it["h"], self.RADIUS)

            ty = y + self.BPY
            if it["show_name"]:
                gc.SetFont(self.f_name, self._name_color(it["name"]))
                gc.DrawText(it["name"], x + self.BPX, ty)
                ty += it["nh"] + 2

            gc.SetFont(self.f_msg, fg)
            for ln in it["lines"]:
                gc.DrawText(ln, x + self.BPX, ty)
                ty += it["lh"]

            gc.SetFont(self.f_small, tcol)
            gc.DrawText(it["tstr"], x + it["w"] - self.BPX - it["timew"], ty + 2)


# ====================== tray icon ======================
class TrayIcon(wx.adv.TaskBarIcon):
    def __init__(self, frame):
        super().__init__()
        self.frame = frame
        size = wx.SystemSettings.GetMetric(wx.SYS_SMALLICON_X) or 16
        self.ic_normal = make_icon(size)
        self.ic_alert = make_icon(size, alert=True)
        self.blink_on = False
        self.tip = "Obrolan"
        self.timer = wx.Timer(self)
        self.Bind(wx.EVT_TIMER, self.on_blink, self.timer)
        self.Bind(wx.adv.EVT_TASKBAR_LEFT_UP, lambda e: self.frame.restore_from_tray())
        self.SetIcon(self.ic_normal, "Obrolan")

    def set_alert(self, on, tip="Obrolan"):
        self.tip = tip
        if on:
            if not self.timer.IsRunning():
                self.blink_on = True
                self.timer.Start(500)
            self.SetIcon(self.ic_alert, tip)
        else:
            self.timer.Stop()
            self.SetIcon(self.ic_normal, "Obrolan")

    def on_blink(self, _evt):
        self.blink_on = not self.blink_on
        self.SetIcon(self.ic_alert if self.blink_on else self.ic_normal, self.tip)

    def CreatePopupMenu(self):
        menu = wx.Menu()
        i_open = menu.Append(wx.ID_ANY, "Buka Obrolan")
        i_pop = menu.AppendCheckItem(wx.ID_ANY, "Popup pesan masuk")
        i_pop.Check(self.frame.cfg["popup"])
        i_snd = menu.AppendCheckItem(wx.ID_ANY, "Suara notifikasi")
        i_snd.Check(self.frame.cfg["sound"])
        menu.AppendSeparator()
        i_exit = menu.Append(wx.ID_ANY, "Keluar")
        menu.Bind(wx.EVT_MENU, lambda e: self.frame.restore_from_tray(), i_open)
        menu.Bind(wx.EVT_MENU, lambda e: self.frame.set_popup(i_pop.IsChecked()), i_pop)
        menu.Bind(wx.EVT_MENU, lambda e: self.frame.set_sound(i_snd.IsChecked()), i_snd)
        menu.Bind(wx.EVT_MENU, lambda e: self.frame.exit_app(), i_exit)
        return menu


# ====================== jendela utama ======================
class ChatFrame(wx.Frame):
    def __init__(self, cfg):
        super().__init__(None, title="Obrolan", size=(480, 660))
        self.cfg = cfg
        self.last_count = -1
        self.force_bottom = True
        self.unread = 0
        self._exiting = False
        self.sound_path = ensure_sound()

        bundle = wx.IconBundle()
        for s in (16, 32, 48):
            bundle.AddIcon(make_icon(s))
        self.SetIcons(bundle)

        panel = wx.Panel(self)
        vbox = wx.BoxSizer(wx.VERTICAL)

        # tombol popup (di atas nama)
        self.btn_popup = wx.ToggleButton(panel, label="")
        self.btn_popup.SetValue(bool(cfg["popup"]))
        self._update_popup_label()
        vbox.Add(self.btn_popup, 0, wx.EXPAND | wx.LEFT | wx.RIGHT | wx.TOP, 8)

        top = wx.BoxSizer(wx.HORIZONTAL)
        top.Add(wx.StaticText(panel, label="Nama:"), 0, wx.ALIGN_CENTER_VERTICAL | wx.RIGHT, 6)
        self.nama = wx.TextCtrl(panel, value=cfg["name"])
        top.Add(self.nama, 1)
        vbox.Add(top, 0, wx.EXPAND | wx.ALL, 8)

        self.view = ChatView(panel)
        vbox.Add(self.view, 1, wx.EXPAND | wx.LEFT | wx.RIGHT, 8)

        bottom = wx.BoxSizer(wx.HORIZONTAL)
        self.input = wx.TextCtrl(panel, style=wx.TE_PROCESS_ENTER)
        self.btn = wx.Button(panel, label="Kirim")
        bottom.Add(self.input, 1, wx.RIGHT, 6)
        bottom.Add(self.btn, 0)
        vbox.Add(bottom, 0, wx.EXPAND | wx.ALL, 8)

        panel.SetSizer(vbox)
        self.CreateStatusBar()

        self.tray = TrayIcon(self)

        self.nama.Bind(wx.EVT_TEXT, self.on_name_changed)
        self.btn_popup.Bind(wx.EVT_TOGGLEBUTTON, lambda e: self.set_popup(self.btn_popup.GetValue()))
        self.input.Bind(wx.EVT_TEXT_ENTER, self.on_send)
        self.btn.Bind(wx.EVT_BUTTON, self.on_send)
        self.Bind(wx.EVT_CLOSE, self.on_close)
        self.Bind(wx.EVT_ACTIVATE, self.on_activate)

        self.timer = wx.Timer(self)
        self.Bind(wx.EVT_TIMER, lambda e: self.refresh(), self.timer)
        self.timer.Start(POLL_MS)
        self.refresh()
        self.input.SetFocus()

    # ---------- pengaturan ----------
    def _update_popup_label(self):
        self.btn_popup.SetLabel("Popup pesan masuk: " + ("ON" if self.cfg["popup"] else "OFF"))

    def set_popup(self, value):
        self.cfg["popup"] = bool(value)
        save_config(self.cfg)
        self.btn_popup.SetValue(self.cfg["popup"])
        self._update_popup_label()

    def set_sound(self, value):
        self.cfg["sound"] = bool(value)
        save_config(self.cfg)

    def on_name_changed(self, _evt):
        self.cfg["name"] = self.nama.GetValue()
        save_config(self.cfg)

    # ---------- tray / jendela ----------
    def restore_from_tray(self):
        self.Iconize(False)
        self.Show()
        self.Raise()
        self.unread = 0
        self.tray.set_alert(False)
        self.input.SetFocus()

    def on_close(self, evt):
        if self._exiting or not evt.CanVeto():
            evt.Skip()
            return
        self.Hide()  # tombol X = sembunyi ke tray, keluar lewat menu tray

    def on_activate(self, evt):
        if evt.GetActive():
            self.unread = 0
            self.tray.set_alert(False)
        evt.Skip()

    def exit_app(self):
        self._exiting = True
        save_config(self.cfg)
        self.timer.Stop()
        self.tray.set_alert(False)
        self.tray.RemoveIcon()
        self.tray.Destroy()
        self.Destroy()

    # ---------- notifikasi ----------
    def notify(self, n):
        was_active = self.IsActive() and self.IsShown() and not self.IsIconized()
        if self.cfg["sound"]:
            play_sound(self.sound_path)
        if was_active:
            return

        self.unread += n
        self.tray.set_alert(True, f"Obrolan - {self.unread} pesan baru")

        if self.cfg["popup"]:
            self.Iconize(False)
            self.Show()
            self.Raise()
        elif not self.IsShown():
            # popup OFF & lagi di tray: munculkan minimize di taskbar supaya bisa berkedip
            self.Iconize(True)
            self.ShowWithoutActivating()
        self.RequestUserAttention(wx.USER_ATTENTION_ERROR)  # taskbar berkedip

    # ---------- baca ----------
    def refresh(self):
        threading.Thread(target=self._fetch, daemon=True).start()

    def _fetch(self):
        try:
            r = requests.get(SERVER, timeout=4)
            r.raise_for_status()
            wx.CallAfter(self.render, r.json())
        except Exception as e:
            wx.CallAfter(self.SetStatusText, f"Gagal konek: {e}")

    def render(self, data):
        if not isinstance(data, list) or len(data) == self.last_count:
            return
        prev = self.last_count
        self.last_count = len(data)
        self.view.set_messages(data, CID, force_bottom=self.force_bottom)
        self.force_bottom = False
        self.SetStatusText(f"Terhubung - {len(data)} pesan")

        if prev >= 0 and len(data) > prev:
            n = sum(1 for m in data[prev:] if str(m.get("cid", "")) != CID)
            if n:
                self.notify(n)

    # ---------- kirim ----------
    def on_send(self, _evt):
        message = self.input.GetValue().strip()
        name = self.nama.GetValue().strip() or "python"
        if not message:
            return
        self.input.Clear()
        self.force_bottom = True
        threading.Thread(target=self._post, args=(name, message), daemon=True).start()

    def _post(self, name, message):
        try:
            r = requests.post(SERVER, json={"name": name, "message": message, "cid": CID}, timeout=4)
            r.raise_for_status()
            wx.CallAfter(self.refresh)
        except Exception as e:
            wx.CallAfter(self.SetStatusText, f"Gagal kirim: {e}")


def main():
    srv = acquire_lock()
    if srv is None and signal_existing():
        return  # sudah ada yang jalan -> dia yang dimunculkan, yang ini keluar

    app = wx.App()
    frame = ChatFrame(load_config())
    if srv:
        threading.Thread(target=listen_for_show, args=(srv, frame.restore_from_tray),
                         daemon=True).start()
    frame.Show()
    app.MainLoop()
    if srv:
        srv.close()


if __name__ == "__main__":
    main()
