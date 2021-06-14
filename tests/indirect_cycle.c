void a(void);
void b(void);

void a(void) {
    void (*p)(void) = b;
}
void b(void) {
    void (*p)(void) = a;
}

int main(void) {
    a();
    b();
}