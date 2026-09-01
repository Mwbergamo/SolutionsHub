/*
 * SolutionsHub — hand-rolled template runtime
 * runtime.js
 *
 * Replaces the Claude-artifact "design canvas" DOM-patching system with a
 * small compile-once / patch-in-place renderer for the SAME template syntax
 * the original app used:
 *
 *   {{path.to.value}}        text / attribute interpolation (plain property
 *                            paths only — no expressions, matches the source)
 *   <sc-if value="{{cond}}">...</sc-if>            conditional block
 *   <sc-for list="{{items}}" as="item">...</sc-for> loop block
 *   data-on-click / data-on-input / data-on-keydown / data-on-change
 *                            event bindings (renamed from the source's
 *                            onClick/onInput/onKeyDown/onChange so they can
 *                            never collide with real, case-insensitive HTML
 *                            event-handler-content attributes before this
 *                            runtime gets a chance to process them)
 *   value="{{path}}" on <input>/<textarea>          two-way-bindable value
 *
 * WHY NOT innerHTML / a vdom diff:
 *   Re-rendering by replacing innerHTML (or by diffing a fresh vdom against
 *   the previous one and recreating changed subtrees) destroys and recreates
 *   DOM nodes. Every time that happens to a text input the user is currently
 *   typing in, the browser drops focus and cursor/selection position — which
 *   is intolerable in this app, since almost every user action (bumping a
 *   quantity, expanding a product) re-renders the WHOLE view, and the user is
 *   very often mid-sentence in a notes/company-name/search field at that
 *   moment.
 *
 *   Instead, each compiled node keeps a persistent handle to its real DOM
 *   node(s) across renders:
 *     - a bound text node's `.data` is only reassigned when the computed
 *       string actually differs from what's already there;
 *     - a bound attribute is only re-written via setAttribute when its
 *       computed string differs from last time;
 *     - a bound <input>/<textarea> only has its `.value` property written
 *       when it differs from the live DOM value (so typing — which already
 *       pushed the new value into state and therefore into the recomputed
 *       view-model — round-trips back to the exact string already on
 *       screen and never touches the DOM node at all);
 *     - `sc-if` blocks are bounded by a persistent comment-marker pair
 *       (`<!--if--><!--/if-->`). Only a TRUE→FALSE or FALSE→TRUE transition
 *       inserts/removes DOM between the markers; staying TRUE just patches
 *       the existing subtree in place, and staying FALSE does nothing;
 *     - `sc-for` blocks are bounded by their own marker pair, and each
 *       rendered item is *itself* bounded by a nested per-item marker pair.
 *       Re-rendering reuses existing item slots by index (patching the Nth
 *       DOM row with the Nth array element's data) and only adds/removes
 *       marker-bounded rows at the tail when the array's length changes —
 *       so a row's own input/select nodes are never recreated just because
 *       a sibling row or a completely unrelated part of the page changed.
 *
 *   Nested control structures (an `sc-for` of categories each containing an
 *   `sc-if` of options, several levels deep — the dominant pattern on the
 *   parts-pricing screens) fall out naturally: each block type compiles its
 *   *body* the same recursive way, so an `sc-if` can contain `sc-for`s and
 *   vice versa to arbitrary depth, each with its own marker pair nested
 *   inside its parent's.
 *
 * Path resolution / scoping:
 *   `{{accentColor}}` used deep inside a triple-nested `sc-for` still needs
 *   to resolve against the *root* view-model, while `{{prod.label}}` inside
 *   a `sc-for list="{{...}}" as="prod"` needs to resolve against the current
 *   loop item. resolvePath() walks a linked scope chain (innermost alias
 *   first) and falls back to the root view-model — matching how the ported
 *   Main.dc.html template actually mixes the two (verified by inspection).
 */

'use strict';

var SolutionsHubRuntime = (function () {

  // ---- path / token resolution ---------------------------------------

  function resolvePath(scope, vm, path) {
    var segments = path.split('.');
    var first = segments[0];
    var s = scope;
    while (s) {
      if (s.alias === first) {
        var val = s.value;
        for (var i = 1; i < segments.length; i++) {
          if (val === null || val === undefined) return undefined;
          val = val[segments[i]];
        }
        return val;
      }
      s = s.parent;
    }
    var v = vm;
    for (var j = 0; j < segments.length; j++) {
      if (v === null || v === undefined) return undefined;
      v = v[segments[j]];
    }
    return v;
  }

  var TOKEN_RE = /\{\{\s*([^}]+?)\s*\}\}/g;

  function parseTokens(str) {
    var tokens = [];
    var lastIndex = 0, m;
    TOKEN_RE.lastIndex = 0;
    while ((m = TOKEN_RE.exec(str))) {
      if (m.index > lastIndex) tokens.push({ lit: str.slice(lastIndex, m.index) });
      tokens.push({ expr: m[1] });
      lastIndex = TOKEN_RE.lastIndex;
    }
    if (lastIndex < str.length) tokens.push({ lit: str.slice(lastIndex) });
    return tokens;
  }

  function renderTokens(tokens, scope, vm) {
    var out = '';
    for (var i = 0; i < tokens.length; i++) {
      var t = tokens[i];
      if (t.lit !== undefined) { out += t.lit; continue; }
      var v = resolvePath(scope, vm, t.expr);
      out += (v === undefined || v === null) ? '' : String(v);
    }
    return out;
  }

  function extractSingleExpr(raw) {
    var m = /^\{\{\s*([^}]+?)\s*\}\}$/.exec((raw || '').trim());
    return m ? m[1] : (raw || '').trim();
  }

  // ---- static-subtree detection ---------------------------------------

  function hasDynamic(node) {
    if (node.nodeType === 3 /* TEXT_NODE */) return node.data.indexOf('{{') !== -1;
    if (node.nodeType !== 1 /* ELEMENT_NODE */) return false;
    var tag = node.tagName;
    if (tag === 'SC-IF' || tag === 'SC-FOR') return true;
    var attrs = node.attributes;
    for (var i = 0; i < attrs.length; i++) {
      var a = attrs[i];
      if (a.name.indexOf('data-on-') === 0) return true;
      if (a.value.indexOf('{{') !== -1) return true;
    }
    var children = node.childNodes;
    for (var j = 0; j < children.length; j++) {
      if (hasDynamic(children[j])) return true;
    }
    return false;
  }

  // ---- compile ----------------------------------------------------------
  // Every compiled "spec" exposes instantiate(scope, vm) -> {dom, update}.
  // `dom` is a Node/DocumentFragment suitable for a single appendChild/
  // insertBefore call by the caller. `update(scope, vm)` re-patches the
  // already-mounted result in place.

  function staticSpec(templateNode) {
    return {
      instantiate: function () {
        return { dom: templateNode.cloneNode(true), update: function () {} };
      }
    };
  }

  function compileChildren(containerNode) {
    var childSpecs = [];
    var child = containerNode.firstChild;
    while (child) {
      childSpecs.push(compileNode(child));
      child = child.nextSibling;
    }
    return {
      instantiate: function (scope, vm) {
        var frag = document.createDocumentFragment();
        var insts = [];
        for (var i = 0; i < childSpecs.length; i++) {
          var inst = childSpecs[i].instantiate(scope, vm);
          frag.appendChild(inst.dom);
          insts.push(inst);
        }
        return {
          dom: frag,
          update: function (scope, vm) {
            for (var i = 0; i < insts.length; i++) insts[i].update(scope, vm);
          }
        };
      }
    };
  }

  function compileTextNode(node) {
    var tokens = parseTokens(node.data);
    return {
      instantiate: function (scope, vm) {
        var textNode = document.createTextNode('');
        var last = null;
        function update(scope, vm) {
          var s = renderTokens(tokens, scope, vm);
          if (s !== last) { textNode.data = s; last = s; }
        }
        update(scope, vm);
        return { dom: textNode, update: update };
      }
    };
  }

  var EVENT_PREFIX = 'data-on-';

  function compileElement(node) {
    var tag = node.tagName;
    var isValueEl = (tag === 'INPUT' || tag === 'TEXTAREA');
    var dynamicAttrs = []; // {name, tokens}
    var eventAttrs = [];   // {event, path}
    var valueTokens = null;

    var attrs = node.attributes;
    for (var i = 0; i < attrs.length; i++) {
      var name = attrs[i].name, value = attrs[i].value;
      if (name.indexOf(EVENT_PREFIX) === 0) {
        eventAttrs.push({ event: name.slice(EVENT_PREFIX.length), path: extractSingleExpr(value) });
      } else if (isValueEl && name === 'value') {
        valueTokens = parseTokens(value);
      } else if (value.indexOf('{{') !== -1) {
        dynamicAttrs.push({ name: name, tokens: parseTokens(value) });
      }
    }

    var childrenSpec = compileChildren(node);

    return {
      instantiate: function (scope, vm) {
        var el = node.cloneNode(false);
        for (var i = 0; i < eventAttrs.length; i++) el.removeAttribute(EVENT_PREFIX + eventAttrs[i].event);
        for (var j = 0; j < dynamicAttrs.length; j++) el.removeAttribute(dynamicAttrs[j].name);
        if (valueTokens) el.removeAttribute('value');

        var childInst = childrenSpec.instantiate(scope, vm);
        el.appendChild(childInst.dom);

        var currentHandlers = {};
        function applyEvents(scope, vm) {
          for (var i = 0; i < eventAttrs.length; i++) {
            var e = eventAttrs[i];
            var fn = resolvePath(scope, vm, e.path);
            if (currentHandlers[e.event] !== fn) {
              if (currentHandlers[e.event]) el.removeEventListener(e.event, currentHandlers[e.event]);
              if (typeof fn === 'function') el.addEventListener(e.event, fn);
              currentHandlers[e.event] = fn;
            }
          }
        }

        var attrLast = {};
        function applyAttrs(scope, vm) {
          for (var i = 0; i < dynamicAttrs.length; i++) {
            var d = dynamicAttrs[i];
            var s = renderTokens(d.tokens, scope, vm);
            if (attrLast[d.name] !== s) { el.setAttribute(d.name, s); attrLast[d.name] = s; }
          }
        }

        function applyValue(scope, vm) {
          if (!valueTokens) return;
          var s = renderTokens(valueTokens, scope, vm);
          // Only touch the live DOM property when it actually differs — this
          // is what keeps a focused input's cursor position intact across an
          // unrelated re-render (see file header).
          if (el.value !== s) el.value = s;
        }

        function update(scope, vm) {
          applyAttrs(scope, vm);
          applyValue(scope, vm);
          applyEvents(scope, vm);
          childInst.update(scope, vm);
        }
        update(scope, vm);
        return { dom: el, update: update };
      }
    };
  }

  function removeRange(startMarker, endMarker) {
    var n = startMarker.nextSibling;
    while (n && n !== endMarker) {
      var next = n.nextSibling;
      n.parentNode.removeChild(n);
      n = next;
    }
  }

  function compileIf(node) {
    var condPath = extractSingleExpr(node.getAttribute('value'));
    var bodySpec = compileChildren(node);
    return {
      instantiate: function (scope, vm) {
        var startMarker = document.createComment('if:' + condPath);
        var endMarker = document.createComment('/if');
        var frag = document.createDocumentFragment();
        frag.appendChild(startMarker);
        frag.appendChild(endMarker);
        var inner = null;

        function apply(scope, vm) {
          var cond = !!resolvePath(scope, vm, condPath);
          if (cond && !inner) {
            inner = bodySpec.instantiate(scope, vm);
            endMarker.parentNode.insertBefore(inner.dom, endMarker);
          } else if (cond && inner) {
            inner.update(scope, vm);
          } else if (!cond && inner) {
            removeRange(startMarker, endMarker);
            inner = null;
          }
        }
        apply(scope, vm);
        return { dom: frag, update: apply };
      }
    };
  }

  function compileFor(node) {
    var listPath = extractSingleExpr(node.getAttribute('list'));
    var alias = node.getAttribute('as');
    var bodySpec = compileChildren(node);
    return {
      instantiate: function (scope, vm) {
        var startMarker = document.createComment('for:' + listPath);
        var endMarker = document.createComment('/for');
        var frag = document.createDocumentFragment();
        frag.appendChild(startMarker);
        frag.appendChild(endMarker);
        var items = []; // {update, removeSelf}

        function apply(scope, vm) {
          var raw = resolvePath(scope, vm, listPath);
          var arr = Array.isArray(raw) ? raw : [];
          for (var i = 0; i < arr.length; i++) {
            var childScope = { alias: alias, value: arr[i], parent: scope };
            if (i < items.length) {
              items[i].update(childScope, vm);
            } else {
              var itemStart = document.createComment('item');
              var itemEnd = document.createComment('/item');
              var bodyInst = bodySpec.instantiate(childScope, vm);
              var itemFrag = document.createDocumentFragment();
              itemFrag.appendChild(itemStart);
              itemFrag.appendChild(bodyInst.dom);
              itemFrag.appendChild(itemEnd);
              endMarker.parentNode.insertBefore(itemFrag, endMarker);
              items.push({
                update: bodyInst.update,
                removeSelf: (function (s, e) { return function () { removeRange(s, e); s.parentNode.removeChild(s); e.parentNode.removeChild(e); }; })(itemStart, itemEnd)
              });
            }
          }
          while (items.length > arr.length) {
            items.pop().removeSelf();
          }
        }
        apply(scope, vm);
        return { dom: frag, update: apply };
      }
    };
  }

  function compileNode(node) {
    if (node.nodeType === 3 /* TEXT_NODE */) {
      if (node.data.indexOf('{{') === -1) return staticSpec(node);
      return compileTextNode(node);
    }
    if (node.nodeType !== 1 /* ELEMENT_NODE */) return staticSpec(node);
    if (!hasDynamic(node)) return staticSpec(node);
    var tag = node.tagName;
    if (tag === 'SC-IF') return compileIf(node);
    if (tag === 'SC-FOR') return compileFor(node);
    return compileElement(node);
  }

  // ---- public API ---------------------------------------------------------

  // Compiles `templateHtml` (a string containing exactly one root element),
  // instantiates it against `component.renderVals()`, mounts it into
  // `mountEl`, and wires `component` so every setState()-triggered change
  // re-patches the live tree. Returns the mounted instance (mostly useful
  // for tests).
  function mountApp(templateHtml, mountEl, component) {
    // Parsed via DOMParser into a standalone (NOT "fully active") document,
    // rather than assigned to a live div's innerHTML. This matters for any
    // <img src="{{...}}">: a fully-active document eagerly fetches an <img>'s
    // src the instant it's parsed, even while the element is still detached
    // — so a live div would fire a bogus request for the literal string
    // "{{logoWhite}}" before compileElement() ever gets a chance to strip
    // and replace it. A DOMParser document has no browsing context, so
    // image loading never fires there; the *real* URL is only ever set (via
    // setAttribute, in update()) on the cloned element used for the actual
    // mounted instance, and only before that clone is inserted into the
    // live, connected document.
    var doc = new DOMParser().parseFromString(templateHtml, 'text/html');
    var rootNode = doc.body.firstElementChild;
    if (!rootNode) throw new Error('SolutionsHubRuntime.mountApp: template has no root element');
    var rootSpec = compileNode(rootNode);

    var vm = component.renderVals();
    var inst = rootSpec.instantiate(null, vm);
    mountEl.appendChild(inst.dom);

    component._onChange = function () {
      vm = component.renderVals();
      inst.update(null, vm);
    };

    return inst;
  }

  return {
    mountApp: mountApp,
    // exposed for unit testing / debugging only:
    _internals: { resolvePath: resolvePath, parseTokens: parseTokens, renderTokens: renderTokens, hasDynamic: hasDynamic }
  };
})();

if (typeof module !== 'undefined' && module.exports) module.exports = SolutionsHubRuntime;
