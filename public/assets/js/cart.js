/**
 * クライアント側カート (localStorage)
 * カートはサーバーセッションではなくブラウザに保持する。
 * - qrcart_cart    : {"<itemId>": <数量 1..99>, ...}
 * - qrcart_remarks : 備考欄の文字列
 * ログイン時・「追加で申込む」時・申込み確定後にクリアされる。
 *
 * window.qrCart に API を公開する。ES5 / no dependency。
 */
(function () {
  'use strict';

  var CART_KEY = 'qrcart_cart';
  var REMARKS_KEY = 'qrcart_remarks';

  function readRaw(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function writeRaw(key, value) {
    try {
      if (value === null) { window.localStorage.removeItem(key); }
      else { window.localStorage.setItem(key, value); }
    } catch (e) { /* private mode 等: 無視 */ }
  }

  function has(obj, k) { return Object.prototype.hasOwnProperty.call(obj, k); }

  /** localStorage から検証済みの {id: qty} を返す */
  function get() {
    var raw = readRaw(CART_KEY);
    if (!raw) { return {}; }
    var obj;
    try { obj = JSON.parse(raw); } catch (e) { return {}; }
    return normalize(obj);
  }

  function normalize(obj) {
    var out = {};
    if (!obj || typeof obj !== 'object') { return out; }
    for (var k in obj) {
      if (!has(obj, k)) { continue; }
      var id = parseInt(k, 10);
      var qty = parseInt(obj[k], 10);
      if (id > 0 && qty >= 1 && qty <= 99) { out[id] = qty; }
    }
    return out;
  }

  function save(cart) {
    writeRaw(CART_KEY, JSON.stringify(cart));
    refreshBadge();
  }

  function setQty(id, qty) {
    id = parseInt(id, 10);
    qty = parseInt(qty, 10);
    if (isNaN(qty)) { qty = 0; }
    var cart = get();
    if (id > 0 && qty >= 1 && qty <= 99) { cart[id] = qty; }
    else { delete cart[id]; }
    save(cart);
  }

  function count() {
    var cart = get();
    var total = 0;
    for (var k in cart) { if (has(cart, k)) { total += cart[k]; } }
    return total;
  }

  function clear() {
    writeRaw(CART_KEY, null);
    writeRaw(REMARKS_KEY, null);
    refreshBadge();
  }

  function toJSON() { return JSON.stringify(get()); }

  function remarks() { return readRaw(REMARKS_KEY) || ''; }
  function setRemarks(s) { writeRaw(REMARKS_KEY, s ? String(s) : null); }

  function refreshBadge() {
    var badge = document.getElementById('cart-badge');
    if (!badge) { return; }
    var n = count();
    badge.textContent = n;
    badge.classList.toggle('d-none', n === 0);
  }

  /** サーバーからの一度きりの指示 (cart-bootstrap) を適用する */
  function applyBootstrap(obj) {
    if (!obj || !obj.action) { return; }
    if (obj.action === 'clear') {
      clear();
    } else if (obj.action === 'load') {
      writeRaw(CART_KEY, JSON.stringify(normalize(obj.cart)));
      setRemarks(obj.remarks || '');
      refreshBadge();
    }
  }

  function sameCart(a, b) {
    var ka = Object.keys(a);
    var kb = Object.keys(b);
    if (ka.length !== kb.length) { return false; }
    for (var i = 0; i < ka.length; i++) {
      if (String(a[ka[i]]) !== String(b[ka[i]])) { return false; }
    }
    return true;
  }

  /**
   * 申込み編集中に、カートが元の内容から変わっている状態で
   * 「申込履歴」「ログアウト」を押したら破棄確認を出す。
   * (変更があるかは、押した時点の localStorage のカートと編集開始時の内容を比べて判定する)
   */
  function guardLinks(originalCart) {
    var selector = 'a[href="orders.php"], #logout-modal a[href="logout.php"]';
    document.addEventListener('click', function (e) {
      var t = e.target;
      var link = (t && t.closest) ? t.closest(selector) : null;
      if (!link || e.defaultPrevented) { return; }
      if (sameCart(get(), originalCart)) { return; }
      if (!window.confirm('申込みを更新せずに、編集を取り消して良いですか?')) {
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);
  }

  function readJsonScript(id) {
    var el = document.getElementById(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || el.innerText || 'null'); } catch (e) { return null; }
  }

  window.qrCart = {
    get: get,
    setQty: setQty,
    count: count,
    clear: clear,
    toJSON: toJSON,
    remarks: remarks,
    setRemarks: setRemarks,
    refreshBadge: refreshBadge,
    applyBootstrap: applyBootstrap,
    guardLinks: guardLinks
  };

  // 同期実行: ページ本体のスクリプトが localStorage を読む前に、
  // サーバーからの指示 (clear / load) を先に反映しておく。
  applyBootstrap(readJsonScript('cart-bootstrap'));

  function whenReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  whenReady(function () {
    refreshBadge();

    var original = readJsonScript('editing-order-cart');
    if (original && typeof original === 'object') {
      guardLinks(normalize(original));
    }

    window.addEventListener('storage', function (e) {
      if (e.key === CART_KEY) { refreshBadge(); }
    });
  });
})();
