const { readFileSync } = require("node:fs");
const vm = require("node:vm");
const assert = require("node:assert/strict");
class Element {
    constructor() {
        this.children = [];
        this.dataset = {};
        this.style = {};
        this.listeners = {};
        this.value = "";
    }
    append(...items) {
        this.children.push(...items);
    }
    replaceChildren(...items) {
        this.children = items;
    }
    setAttribute(k, v) {
        this[k] = v;
    }
    addEventListener(k, fn) {
        this.listeners[k] = fn;
    }
    scrollIntoView() {}
    setCustomValidity(message) { this.validationMessage = message; }
    reportValidity() { this.reportedMessage = this.validationMessage; }

}
const ids = {};
for (const id of [
    "flow-graph",
    "flow-builder",
    "ivr-cards",
    "ivr-connections",
    "flow-preview",
    "ivr-properties",
    "flow-error",
    "ivr-issues",
    "ivr-assets",
    "ivr-routes",
    "ivr-json",
    "ivr-layout",
    "preview-flow",
    "ivr-save",
    "source-id",
    "flow-name",
])
    ids[id] = new Element();
ids["flow-graph"].value = JSON.stringify({
    start: "menu",
    nodes: [
        {
            id: "menu",
            type: "input",
            audio_id: 1,
            timeout_seconds: 5,
            branches: { 1: "done" },
            invalid: "done",
            timeout: "done",
        },
        { id: "done", type: "end" },
    ],
});
ids["ivr-assets"].textContent = JSON.stringify([{ id: 1, name: "Welcome" }]);
ids["ivr-routes"].textContent = "[]";
const add = new Element();
add.dataset.addIvr = "playback";
const clone = new Element();
clone.dataset = {
    source: "42",
    name: "Existing flow",
    flow: ids["flow-graph"].value,
};
const document = {
    getElementById: (id) => ids[id],
    createElement: () => new Element(),
    createElementNS: () => new Element(),
    querySelectorAll: (s) =>
        s === "[data-add-ivr]" ? [add] : s === ".clone-flow" ? [clone] : [],
};
vm.runInNewContext(readFileSync("public/js/ivr-builder.js", "utf8"), {
    document,
    Option: function (text, value) {
        this.textContent = text;
        this.value = value;
    },
});
assert.equal(ids["ivr-cards"].children.length, 2);
assert.equal(ids["ivr-issues"].children.length, 0);
let blocked = false;
ids["flow-builder"].listeners.submit({
    preventDefault() {
        blocked = true;
    },
});
assert.equal(blocked, false);
add.onclick();
assert.equal(JSON.parse(ids["flow-graph"].value).nodes.length, 3);
assert(ids["ivr-issues"].children.length > 0);
ids["flow-builder"].listeners.submit({
    preventDefault() {
        blocked = true;
    },
});
assert.equal(blocked, true);
// Duplicate existing version restores its complete graph and source family reference.
clone.onclick();
assert.equal(ids["source-id"].value, "42");
assert.equal(ids["flow-name"].value, "Existing flow");
assert.equal(ids["ivr-issues"].children.length, 0);
// Rejected keys restore the valid value without leaving native validation blocked.
const keyField = () => ids['ivr-properties'].children.find(e => e.className === 'ivr-branch').children[0];
for (const invalid of ['x', '']) {
    const key = keyField();
    key.value = invalid;
    key.onchange();
    assert.equal(key.value, '1');
    assert.equal(key.validationMessage, '');
    assert(key.reportedMessage);
    assert.equal(JSON.parse(ids['flow-graph'].value).nodes[0].branches['1'], 'done');
}
// Duplicate digit is rejected as well.
const addKey = ids['ivr-properties'].children.find(e => e.textContent === '+ Add key');
addKey.onclick();
const key = keyField(); key.value = '2'; key.onchange();
assert.equal(key.value, '1'); assert.equal(key.validationMessage, '');
// A valid replacement still updates the generated graph.
key.value = '3'; key.onchange();
assert.equal(JSON.parse(ids['flow-graph'].value).nodes[0].branches['3'], 'done');
clone.onclick();
blocked = false;
ids['flow-builder'].listeners.submit({preventDefault(){blocked=true;}});
assert.equal(blocked, false);
// A cyclic saved graph is surfaced rather than hanging validation.
clone.dataset.flow = JSON.stringify({
    start: "menu",
    nodes: [{ id: "menu", type: "timeout", seconds: 1, next: "menu" }],
});
clone.onclick();
assert(ids["ivr-issues"].children.length > 0);
console.log(
    "Visual IVR rendering, serialization, validation and duplication checks passed.",
);
