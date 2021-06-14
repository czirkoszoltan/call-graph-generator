#include <stdlib.h>
#include <time.h>
#include <stdio.h>

void a(void) { printf("a\n"); }
void b(void) { printf("b\n"); }
void c(void) { printf("c\n"); }

int main(void) {
    void (*funcs[])(void) = { a, b };
    
    srand(time(NULL));
    funcs[rand()%2]();
    c();
    free(malloc(100));
}
