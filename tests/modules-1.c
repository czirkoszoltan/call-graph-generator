static void multiple_modules() {}

static void another_function() {}

void do_not_optimize_static_funcs() {
  another_function();
  multiple_modules();
}
