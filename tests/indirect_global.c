#include <stdio.h>

void a() { printf("a\n"); }
void b() { printf("b\n"); }

void (*funcs[])(void) = { a, b };

int main(int argc, char *argv[]) {
    funcs[argc%2]();
}
