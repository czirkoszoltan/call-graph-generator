#include <iostream>

class A {
  public:
    A();
    void f();
    ~A();
};

A::A() {
    std::cout << "A::A\n";
}

void A::f() {
    std::cout << "A::f\n";
}

A::~A() {
    std::cout << "A::~A\n";
}

void f(int i) {
    std::cout << i;
}

int main() {
    A a1;
    a1.f();
    f(5);
}
