#!/usr/bin/env python3
path = "app.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()

orig = src

# ---------------------------------------------------------------------------
# 1. bindEvents(): wire todoDescriptionInput / todoAssigneeSelect / todoDueDateInput.
# ---------------------------------------------------------------------------
old1 = """    var taskDueDateInput = document.getElementById('taskDueDateInput');
    if (taskDueDateInput) {
      taskDueDateInput.addEventListener('input', function (e) {
        state.taskDraftDueDate = e.target.value;
      });
    }

    var dupFilterInput = document.getElementById('dupFilterInput');"""
assert src.count(old1) == 1, "old1 count=%d" % src.count(old1)
new1 = """    var taskDueDateInput = document.getElementById('taskDueDateInput');
    if (taskDueDateInput) {
      taskDueDateInput.addEventListener('input', function (e) {
        state.taskDraftDueDate = e.target.value;
      });
    }

    var todoDescriptionInput = document.getElementById('todoDescriptionInput');
    if (todoDescriptionInput) {
      todoDescriptionInput.addEventListener('input', function (e) {
        state.todoDraftDescription = e.target.value;
      });
    }
    var todoAssigneeSelect = document.getElementById('todoAssigneeSelect');
    if (todoAssigneeSelect) {
      todoAssigneeSelect.addEventListener('change', function (e) {
        state.todoDraftAssignee = e.target.value;
      });
    }
    var todoDueDateInput = document.getElementById('todoDueDateInput');
    if (todoDueDateInput) {
      todoDueDateInput.addEventListener('input', function (e) {
        state.todoDraftDueDate = e.target.value;
      });
    }

    var dupFilterInput = document.getElementById('dupFilterInput');"""
src = src.replace(old1, new1, 1)

# ---------------------------------------------------------------------------
# 2. Dispatcher: open-todos / todos-close / todo-add-open / todo-add-cancel /
#    todo-save / todo-toggle-done / todo-reassign / todo-remove, right after
#    the existing task-toggle-done block.
# ---------------------------------------------------------------------------
old2 = """      var formstackTab = completing ? window.open('', '_blank') : null;
      toggleTaskDone(parseInt(el.getAttribute('data-task'), 10), completing, formstackTab);
    } else if (action === 'open-customer-task') {"""
assert src.count(old2) == 1, "old2 count=%d" % src.count(old2)
new2 = """      var formstackTab = completing ? window.open('', '_blank') : null;
      toggleTaskDone(parseInt(el.getAttribute('data-task'), 10), completing, formstackTab);
    } else if (action === 'open-todos') {
      loadTodoList(state.selectedCustomer.customer.id);
    } else if (action === 'todos-close') {
      state.todoView = null;
      render();
    } else if (action === 'todo-add-open') {
      state.todoAddOpen = true;
      state.todoDraftDescription = '';
      state.todoDraftAssignee = ''; // blank-first, per the same "a rep has to choose" rule as task-add-open
      state.todoDraftDueDate = '';
      state.todoListError = null;
      render();
    } else if (action === 'todo-add-cancel') {
      state.todoAddOpen = false;
      state.todoListError = null;
      render();
    } else if (action === 'todo-save') {
      saveGeneralTodo(parseInt(el.getAttribute('data-customer'), 10));
    } else if (action === 'todo-toggle-done') {
      var todoWasDone = el.getAttribute('data-completed') === '1';
      toggleGeneralTodoDone(parseInt(el.getAttribute('data-task'), 10), !todoWasDone, parseInt(el.getAttribute('data-customer'), 10));
    } else if (action === 'todo-reassign') {
      var reassignTodoId = parseInt(el.getAttribute('data-todo'), 10);
      var reassignName = state.todoReassignDraft[reassignTodoId];
      if (!reassignName) {
        state.todoListError = 'Pick who to reassign this to-do to first.';
        render();
      } else {
        reassignGeneralTodo(reassignTodoId, reassignName, parseInt(el.getAttribute('data-customer'), 10));
      }
    } else if (action === 'todo-remove') {
      removeGeneralTodo(parseInt(el.getAttribute('data-todo'), 10), parseInt(el.getAttribute('data-customer'), 10));
    } else if (action === 'open-customer-task') {"""
src = src.replace(old2, new2, 1)

# ---------------------------------------------------------------------------
# 3. Register the delegated 'change' handler for the per-row reassign selects.
# ---------------------------------------------------------------------------
old3 = "  root.addEventListener('change', onRiskScanAssignSelectChange);\n"
assert src.count(old3) == 1, "old3 count=%d" % src.count(old3)
new3 = old3 + "  root.addEventListener('change', onTodoReassignSelectChange);\n"
src = src.replace(old3, new3, 1)

assert src != orig, "no changes made"
with open(path, "w", encoding="utf-8") as f:
    f.write(src)

print("OK -- part 3 (bindEvents + dispatcher + delegated change listener) applied")
